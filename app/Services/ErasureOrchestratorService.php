<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityEventEnum;
use App\Models\ErasureRequest;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\ApiKeys\Erasure\ApiKeysEraser;
use Qiick\HttpBasics\Erasure\HttpBasicsEraser;
use Qiick\Oidc\Erasure\OidcEraser;
use Qiick\Outbound\Erasure\OutboundEraser;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Excubia's resumable user erasure (#381), the product orchestrator of the qiick fleet erasure
 * standard (`.qiick/standards/erasure.md`).
 *
 * begin() is step 0, inside the caller's transaction under the user lock: it writes the
 * `erasure_requests` row with the encrypted snapshot and locks the person out (inactive, unusable
 * password, no remember token or sessions, principal erased so every key stops). run() then
 * replays every step that is not done, from the snapshot, never from the user row:
 *
 * 1. api-keys: ApiKeysEraser, `anonymise` (keys revoked and inert, attribution scrubbed);
 * 2. oidc, 3. outbound, 4. http-basics: the package erasers, `delete`;
 * 5. product: assistant chats, passkeys, reset tokens, notifications, and the person's values
 *    scrubbed from the activity log (`properties`, `attribute_changes`);
 * 6. access: the user row becomes an anonymised, soft-deleted tombstone, the principal stays
 *    erased with an anonymised name;
 * 7. close: one `erasure_completed` activity entry (case id and counts), then `completed` with
 *    the snapshot and the user link dropped, in one transaction.
 *
 * Deviation from the standard's step 5 (documented in docs/privacy.md): the user row is kept as
 * an anonymised soft-deleted tombstone instead of AccessEraser + forceDelete, because API keys
 * and audit rows reference it with RESTRICT foreign keys and the audit trail must stay intact.
 *
 * A failed step marks the case `failed` with a code and no message; `excubia:resume-erasures`
 * retries it. A snapshot that no longer decrypts is `snapshot_unreadable` and is never retried.
 */
class ErasureOrchestratorService
{
    public const MODE = ApiKeysEraser::ANONYMISE;

    public const STEPS = ['api-keys', 'oidc', 'outbound', 'http-basics', 'product', 'access'];

    private const ERASED_TEXT = '[erased]';

    /** Attribute names whose values are personal wherever an activity payload holds them, old values included. */
    private const PERSONAL_ATTRIBUTES = ['first_name', 'last_name', 'name', 'email', 'username', 'last_login_ip'];

    /** The request data every activity records (AppServiceProvider): the person's own when they caused it. */
    private const REQUEST_ATTRIBUTES = ['ip', 'user_agent', 'identifier'];

    public function __construct(
        private PrincipalDirectory $principals,
        private ApiKeysEraser $apiKeysEraser,
        private OidcEraser $oidcEraser,
        private OutboundEraser $outboundEraser,
        private HttpBasicsEraser $httpBasicsEraser,
    ) {}

    /**
     * Step 0. Call it inside a transaction that holds the user's row lock; an open case of the
     * same user is returned instead of a second one.
     *
     * @side-effect Deactivates the user and their principal, deletes their sessions.
     */
    public function begin(User $user): ErasureRequest
    {
        $openRequest = ErasureRequest::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', ErasureRequest::STATUS_COMPLETED)
            ->first();

        if ($openRequest instanceof ErasureRequest) {
            return $openRequest;
        }

        // Every column, whatever the caller loaded (the profile page loads a partial user).
        $user = User::query()->whereKey($user->getKey())->firstOrFail();
        $principal = $this->principals->forMember($user, null);
        /** @var array<string, int> $apiKeyIdsByPublicId */
        $apiKeyIdsByPublicId = $user->apiKeys()->pluck('id', 'uuid')->all();

        $erasureRequest = ErasureRequest::query()->create([
            'mode' => self::MODE,
            'status' => ErasureRequest::STATUS_PENDING,
            'steps' => array_fill_keys(self::STEPS, ['status' => 'pending']),
            'subject' => [
                'userId' => (string) $user->getKey(),
                'userPublicId' => $user->public_id,
                'publicIds' => [$principal->id, ...array_keys($apiKeyIdsByPublicId)],
                // Only values specific enough to search free text everywhere: a lone first name
                // would match other people's text ("Ann" in "Annual"). The user's own activity
                // rows lose their name columns by key (PERSONAL_ATTRIBUTES) instead.
                'identifiers' => array_values(array_unique(array_filter(
                    [$user->email, $user->username, $user->name, $user->last_login_ip],
                    fn (mixed $identifier): bool => is_string($identifier) && mb_strlen(trim($identifier)) >= 3,
                ))),
                'email' => $user->email,
                'apiKeyIdsByPublicId' => $apiKeyIdsByPublicId,
            ],
            'user_id' => $user->id,
            'requested_at' => now(),
        ]);

        // From here on no login path accepts the user, and no key of their principal works.
        $user->forceFill(['is_active' => false, 'password' => Str::random(64), 'remember_token' => null])->saveQuietly();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->principals->markErased($principal);

        return $erasureRequest;
    }

    /**
     * Whether the user's erasure began: an open case, or the tombstone it left.
     */
    public function isBeingErased(User $user): bool
    {
        return User::withTrashed()->whereKey($user->getKey())->whereNotNull('deleted_at')->exists()
            || ErasureRequest::query()->where('user_id', $user->getKey())->where('status', '!=', ErasureRequest::STATUS_COMPLETED)->exists();
    }

    /**
     * Locks the user's row and refuses the write once their erasure began. Call it first in the
     * transaction of any write to the user's own data that a request authorised earlier (profile,
     * API keys, an admin's update): step 0 holds the same lock, so the write either commits
     * before the erasure starts, and the erasers then take it, or it is refused.
     *
     * @throws AuthorizationException when the user is being erased
     */
    public function lockLiveUser(User $user): void
    {
        User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->first();

        if ($this->isBeingErased($user)) {
            throw new AuthorizationException;
        }
    }

    /** How long one executor holds a case; a crashed executor's case is taken over after it. */
    public const LEASE_MINUTES = 15;

    /**
     * Claims the case and runs every step that is not done, then closes it. Never throws for a
     * failed step: the case is left `failed` with a code for `excubia:resume-erasures`.
     *
     * Only one executor advances a case: the claim is a compare-and-set on the status and the
     * lease, and every checkpoint and the close check the claim token under a row lock, so a
     * deletion and the resume command (or two of either) never overwrite each other.
     *
     * @return bool false when a step failed or the snapshot is unreadable; true when the case is
     *              closed, was already closed, or another executor holds it
     */
    public function run(ErasureRequest $erasureRequest, bool $isDryRun = false): bool
    {
        try {
            $subject = $erasureRequest->readSubject();
        } catch (DecryptException) {
            if (! $isDryRun) {
                $erasureRequest->forceFill(['status' => ErasureRequest::STATUS_FAILED, 'last_error_code' => ErasureRequest::ERROR_SNAPSHOT_UNREADABLE])->save();
                // The admin channel: the log names the case, never the person.
                Log::error('A user erasure cannot read its snapshot and needs an admin.', ['erasure_request_id' => $erasureRequest->id]);
            }

            return false;
        }

        if ($subject === null || $erasureRequest->status === ErasureRequest::STATUS_COMPLETED || $isDryRun) {
            return true;
        }

        $claimToken = Str::random(40);

        if (! $this->claim($erasureRequest, $claimToken)) {
            return true;
        }

        foreach (self::STEPS as $step) {
            if (($erasureRequest->steps[$step]['status'] ?? null) === 'done') {
                continue;
            }

            try {
                $isHeld = $this->runFencedStep($erasureRequest, $step, $subject, $claimToken);
            } catch (Throwable $exception) {
                // No message and no exception: a query's bindings would carry the person's data.
                Log::warning('A user erasure step failed.', ['erasure_request_id' => $erasureRequest->id, 'step' => $step, 'exception' => $exception::class]);
                $this->checkpoint($erasureRequest, $claimToken, ['status' => ErasureRequest::STATUS_FAILED, 'last_error_code' => 'step_failed:'.$step, 'claim_token' => null, 'claimed_until' => null]);

                return false;
            }

            if (! $isHeld) {
                return true;
            }
        }

        $this->close($erasureRequest, $claimToken);

        return true;
    }

    /**
     * Runs one step inside the transaction that locks the case and checks the claim token, and
     * marks it done in the same transaction: an executor that lost the case (its lease ran out
     * while it was suspended) neither runs a step nor writes anything, and a step's effects and
     * its checkpoint commit together. The package erasers join this transaction as a savepoint.
     *
     * @param  array{userId: string, userPublicId: string, publicIds: list<string>, identifiers: list<string>, apiKeyIdsByPublicId: array<string, int>, email?: string}  $subject
     * @return bool false when another executor holds the case
     */
    private function runFencedStep(ErasureRequest $erasureRequest, string $step, array $subject, string $claimToken): bool
    {
        return DB::transaction(function () use ($erasureRequest, $step, $subject, $claimToken): bool {
            $stored = ErasureRequest::query()->whereKey($erasureRequest->id)->lockForUpdate()->first();

            if (! $stored instanceof ErasureRequest || $stored->claim_token !== $claimToken || $stored->status !== ErasureRequest::STATUS_RUNNING) {
                return false;
            }

            $changed = $this->runStep($step, $subject);
            $stored->forceFill([
                'steps' => [...$stored->steps, $step => ['status' => 'done', 'changed' => $changed]],
                'claimed_until' => now()->addMinutes(self::LEASE_MINUTES),
            ])->save();
            $erasureRequest->setRawAttributes($stored->getAttributes(), true);

            return true;
        });
    }

    /**
     * Takes the case when it is pending, failed, or running under a lease that ran out.
     */
    private function claim(ErasureRequest $erasureRequest, string $claimToken): bool
    {
        $isClaimed = ErasureRequest::query()
            ->whereKey($erasureRequest->id)
            ->where(fn ($query) => $query
                ->whereIn('status', [ErasureRequest::STATUS_PENDING, ErasureRequest::STATUS_FAILED])
                ->orWhere(fn ($runningQuery) => $runningQuery->where('status', ErasureRequest::STATUS_RUNNING)->where('claimed_until', '<', now())))
            ->where(fn ($query) => $query->whereNull('last_error_code')->orWhere('last_error_code', '!=', ErasureRequest::ERROR_SNAPSHOT_UNREADABLE))
            ->update([
                'status' => ErasureRequest::STATUS_RUNNING,
                'claim_token' => $claimToken,
                'claimed_until' => now()->addMinutes(self::LEASE_MINUTES),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;

        if ($isClaimed) {
            // The steps as stored now, not as this executor first read them.
            $erasureRequest->refresh();
        }

        return $isClaimed;
    }

    /**
     * Writes `$changes` only while this executor still holds the case, and renews its lease.
     *
     * @param  array<string, mixed>  $changes
     */
    private function checkpoint(ErasureRequest $erasureRequest, string $claimToken, array $changes): bool
    {
        return DB::transaction(function () use ($erasureRequest, $claimToken, $changes): bool {
            $stored = ErasureRequest::query()->whereKey($erasureRequest->id)->lockForUpdate()->first();

            if (! $stored instanceof ErasureRequest || $stored->claim_token !== $claimToken || $stored->status !== ErasureRequest::STATUS_RUNNING) {
                return false;
            }

            $stored->forceFill([...['claimed_until' => now()->addMinutes(self::LEASE_MINUTES)], ...$changes])->save();
            $erasureRequest->setRawAttributes($stored->getAttributes(), true);

            return true;
        });
    }

    /**
     * @param  array{userId: string, userPublicId: string, publicIds: list<string>, identifiers: list<string>, apiKeyIdsByPublicId: array<string, int>, email?: string}  $subject
     * @return int rows changed
     */
    private function runStep(string $step, array $subject): int
    {
        ['userId' => $userId, 'userPublicId' => $userPublicId, 'publicIds' => $publicIds, 'identifiers' => $identifiers] = $subject;

        return match ($step) {
            'api-keys' => $this->apiKeysEraser->erase($userId, $userPublicId, ApiKeysEraser::ANONYMISE, $publicIds, $identifiers)->changed(),
            'oidc' => $this->oidcEraser->erase($userId, $userPublicId, OidcEraser::DELETE, $publicIds, $identifiers)->changed(),
            'outbound' => $this->outboundEraser->erase($userId, $userPublicId, OutboundEraser::DELETE, $publicIds)->changed(),
            'http-basics' => $this->httpBasicsEraser->erase($userId, $userPublicId, HttpBasicsEraser::DELETE, $publicIds)->changed(),
            'product' => DB::transaction(fn (): int => $this->eraseProductData($userId, $userPublicId, $subject['email'] ?? null, $identifiers)),
            'access' => DB::transaction(fn (): int => $this->tombstoneUser($userId, $userPublicId)),
            default => throw new LogicException("Unknown erasure step [{$step}]."),
        };
    }

    /**
     * Assistant chats, passkeys, reset tokens and notifications go; activity rows stay, with the
     * person's values replaced in their payloads: their own model events and the rows they caused
     * (with the request IP and user agent), failed logins under their email or username, and the
     * alerts that name them (scrubAlertTraces()).
     *
     * @param  list<string>  $identifiers
     */
    private function eraseProductData(string $userId, string $userPublicId, ?string $email, array $identifiers): int
    {
        $morphClass = (new User)->getMorphClass();
        $conversationIds = DB::table('agent_conversations')
            ->where('participant_type', $morphClass)
            ->where('participant_id', $userId)
            ->pluck('id');

        $changed = DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->delete()
            + DB::table('agent_conversations')->whereIn('id', $conversationIds)->delete()
            + DB::table('passkeys')->where('user_id', $userId)->delete()
            // The subject's own email field, never the free-text identifiers: a name may equal
            // another account's email.
            + ($email === null ? 0 : DB::table('password_reset_tokens')->where('email', $email)->delete())
            + DB::table('notifications')->where('notifiable_type', $morphClass)->where('notifiable_id', $userId)->delete();

        $activities = Activity::query()->where(fn ($query) => $query
            ->where(fn ($subjectQuery) => $subjectQuery->where('subject_type', $morphClass)->where('subject_id', $userId))
            ->orWhere(fn ($causerQuery) => $causerQuery->where('causer_type', $morphClass)->where('causer_id', $userId)));

        foreach ($activities->cursor() as $activity) {
            // Only the user's own model events hold their attributes; a row they caused about a
            // project keeps the project's `name`.
            $isAboutUser = $activity->subject_type === $morphClass && (string) $activity->subject_id === $userId;
            $isCausedByUser = $activity->causer_type === $morphClass && (string) $activity->causer_id === $userId;
            $properties = $this->scrub($activity->properties?->toArray() ?? [], $identifiers, $isAboutUser, $isCausedByUser);
            $attributeChanges = $this->scrub($activity->attribute_changes?->toArray() ?? [], $identifiers, $isAboutUser, false);

            if ($properties !== ($activity->properties?->toArray() ?? []) || $attributeChanges !== ($activity->attribute_changes?->toArray() ?? [])) {
                // Query builder: saving the activity model would log nothing, but stays explicit.
                DB::table($activity->getTable())->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                    'attribute_changes' => $activity->attribute_changes === null ? null : json_encode($attributeChanges, JSON_THROW_ON_ERROR),
                ]);
                $changed++;
            }
        }

        // Failed logins are anonymous rows: the typed email or username is their only link.
        $loginIdentifiers = array_values(array_filter([$email, ...$identifiers], is_string(...)));
        $failedLogins = Activity::query()->where('event', 'failed')->whereNull('causer_id');

        foreach ($failedLogins->cursor() as $failedLogin) {
            $typedIdentifier = $failedLogin->properties?->get('identifier');

            if (is_string($typedIdentifier) && in_array(mb_strtolower($typedIdentifier), array_map(mb_strtolower(...), $loginIdentifiers), true)) {
                DB::table($failedLogin->getTable())->where('id', $failedLogin->id)->update([
                    'properties' => json_encode($this->scrub($failedLogin->properties->toArray(), $loginIdentifiers, false, true), JSON_THROW_ON_ERROR),
                ]);
                $changed++;
            }
        }

        return $changed + $this->scrubAlertTraces($userPublicId);
    }

    /**
     * The alerts that name the person, found by their public id only (never by text, which could
     * match someone else): the name in the alert history and in the in-app notifications of
     * those alerts becomes `[erased]`, and failed alert jobs that name them are deleted. Messages
     * still queued redact the name when they run (DeliverAlertJob).
     */
    private function scrubAlertTraces(string $userPublicId): int
    {
        $changed = 0;
        $namesByAlertUuid = [];

        foreach (DB::table('alert_logs')->where('subject_public_id', $userPublicId)->get(['id', 'uuid', 'summary']) as $alertLog) {
            $summary = (string) $alertLog->summary;

            // ReportAlertsService writes the name as the summary's last "(assigned to …)".
            if (preg_match('/ \(assigned to (.+)\)$/u', $summary, $matches) === 1) {
                $namesByAlertUuid[(string) $alertLog->uuid] = $matches[1];
                $summary = mb_substr($summary, 0, -mb_strlen($matches[0])).' (assigned to '.self::ERASED_TEXT.')';
            }

            $changed += DB::table('alert_logs')->where('id', $alertLog->id)->update(['summary' => $summary, 'subject_public_id' => null]);
        }

        foreach ($namesByAlertUuid as $alertUuid => $name) {
            // A UUID has no LIKE wildcard: this matches exactly the notifications of this alert.
            foreach (DB::table('notifications')->where('data', 'like', '%'.$alertUuid.'%')->get(['id', 'data']) as $notification) {
                $data = json_decode((string) $notification->data, true);

                if (! is_array($data)) {
                    continue;
                }

                $scrubbedData = $this->scrub($data, [$name], false, false);

                if ($scrubbedData !== $data) {
                    $changed += DB::table('notifications')->where('id', $notification->id)->update(['data' => json_encode($scrubbedData, JSON_THROW_ON_ERROR)]);
                }
            }
        }

        return $changed + DB::table('failed_jobs')->where('payload', 'like', '%'.$userPublicId.'%')->where('payload', 'like', '%DeliverAlertJob%')->delete();
    }

    /**
     * Every identifier in a string becomes `[erased]`, at any depth, and so does every
     * value of a personal attribute of the user's own model events (an old name the snapshot no
     * longer knows).
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $identifiers
     * @return array<array-key, mixed>
     */
    private function scrub(array $payload, array $identifiers, bool $isAboutUser, bool $isCausedByUser): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->scrub($value, $identifiers, $isAboutUser, false);
            } elseif ($isCausedByUser && in_array($key, self::REQUEST_ATTRIBUTES, true) && $value !== null && $value !== self::ERASED_TEXT) {
                $payload[$key] = self::ERASED_TEXT;
            } elseif ($isAboutUser && in_array($key, self::PERSONAL_ATTRIBUTES, true) && $value !== null && $value !== self::ERASED_TEXT) {
                $payload[$key] = self::ERASED_TEXT;
            } elseif (is_string($value) && $identifiers !== [] && Str::contains($value, $identifiers, ignoreCase: true)) {
                $payload[$key] = str_ireplace($identifiers, self::ERASED_TEXT, $value);
            }
        }

        return $payload;
    }

    /**
     * The user row becomes a soft-deleted tombstone: personal columns replaced by unique values
     * from the public id, so the email and username can be reused on every database engine.
     */
    private function tombstoneUser(string $userId, string $userPublicId): int
    {
        $user = User::withTrashed()->whereKey($userId)->lockForUpdate()->first();

        if (! $user instanceof User || $user->trashed()) {
            return 0;
        }

        // Erased since step 0: forMember() refuses an erased principal, so read it directly.
        Principal::query()->where('type', 'user')->where('user_id', $user->id)->update(['name' => 'Deleted principal '.$userPublicId]);

        // Quiet: erasing the row must not create a new activity entry with the old values.
        $user->forceFill([
            'first_name' => 'Deleted',
            'last_name' => null,
            'email' => 'deleted+'.$userPublicId.'@example.invalid',
            'username' => 'deleted_'.str_replace('-', '', $userPublicId),
            'is_active' => false,
            'email_verified_at' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locale' => config('app.locale', 'en'),
            'timezone' => 'UTC',
            'password' => Str::random(64),
            'alert_email_filters' => null,
            'has_alert_emails' => false,
            'has_weekly_digest' => false,
        ])->saveQuietly();
        $user->delete();

        return 1;
    }

    /**
     * One audit entry with the case id and the counts, then the case closes and forgets the
     * person, in one transaction, and only by the executor that holds it. The entry is written as
     * a plain row: no causer, no request IP or user agent, no activity-log buffer.
     */
    private function close(ErasureRequest $erasureRequest, string $claimToken): void
    {
        DB::transaction(function () use ($erasureRequest, $claimToken): void {
            $stored = ErasureRequest::query()->whereKey($erasureRequest->id)->lockForUpdate()->first();

            if (! $stored instanceof ErasureRequest || $stored->claim_token !== $claimToken || $stored->status !== ErasureRequest::STATUS_RUNNING) {
                return;
            }

            Activity::query()->create([
                'log_name' => config('activitylog.default_log_name', 'default'),
                'event' => ActivityEventEnum::ErasureCompleted->value,
                'description' => 'User erased',
                'properties' => [
                    'erasure_request_id' => $stored->id,
                    'changed' => array_map(fn (array $step): int => $step['changed'] ?? 0, $stored->steps),
                ],
            ]);

            $stored->forceFill([
                'status' => ErasureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'subject' => null,
                'user_id' => null,
                'claim_token' => null,
                'claimed_until' => null,
                'last_error_code' => null,
            ])->save();
        });
    }
}
