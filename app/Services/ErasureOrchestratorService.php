<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityEventEnum;
use App\Models\ErasureRequest;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
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
                'identifiers' => array_values(array_unique(array_filter(
                    [$user->email, $user->username, $user->name, $user->first_name, $user->last_name, $user->last_login_ip],
                    fn (mixed $identifier): bool => is_string($identifier) && mb_strlen(trim($identifier)) >= 3,
                ))),
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
     * Runs every step that is not done, then closes the case. Never throws for a failed step:
     * the case is left `failed` with a code for `excubia:resume-erasures`.
     */
    public function run(ErasureRequest $erasureRequest, bool $isDryRun = false): bool
    {
        try {
            $subject = $erasureRequest->readSubject();
        } catch (DecryptException) {
            $erasureRequest->forceFill(['status' => ErasureRequest::STATUS_FAILED, 'last_error_code' => ErasureRequest::ERROR_SNAPSHOT_UNREADABLE])->save();

            return false;
        }

        if ($subject === null || $erasureRequest->status === ErasureRequest::STATUS_COMPLETED) {
            return true;
        }

        if ($isDryRun) {
            return true;
        }

        $erasureRequest->forceFill(['status' => ErasureRequest::STATUS_RUNNING, 'attempts' => $erasureRequest->attempts + 1])->save();

        foreach (self::STEPS as $step) {
            if (($erasureRequest->steps[$step]['status'] ?? null) === 'done') {
                continue;
            }

            try {
                $changed = $this->runStep($step, $subject);
            } catch (Throwable $exception) {
                report($exception);
                $erasureRequest->forceFill(['status' => ErasureRequest::STATUS_FAILED, 'last_error_code' => 'step_failed:'.$step])->save();

                return false;
            }

            $erasureRequest->forceFill(['steps' => [...$erasureRequest->steps, $step => ['status' => 'done', 'changed' => $changed]]])->save();
        }

        $this->close($erasureRequest);

        return true;
    }

    /**
     * @param  array{userId: string, userPublicId: string, publicIds: list<string>, identifiers: list<string>, apiKeyIdsByPublicId: array<string, int>}  $subject
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
            'product' => DB::transaction(fn (): int => $this->eraseProductData($userId, $identifiers)),
            'access' => DB::transaction(fn (): int => $this->tombstoneUser($userId, $userPublicId)),
            default => throw new LogicException("Unknown erasure step [{$step}]."),
        };
    }

    /**
     * Assistant chats, passkeys, reset tokens and notifications go; activity rows stay, with the
     * person's values replaced in their payloads.
     *
     * @param  list<string>  $identifiers
     */
    private function eraseProductData(string $userId, array $identifiers): int
    {
        $morphClass = (new User)->getMorphClass();
        $conversationIds = DB::table('agent_conversations')
            ->where('participant_type', $morphClass)
            ->where('participant_id', $userId)
            ->pluck('id');

        $changed = DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->delete()
            + DB::table('agent_conversations')->whereIn('id', $conversationIds)->delete()
            + DB::table('passkeys')->where('user_id', $userId)->delete()
            + DB::table('password_reset_tokens')->whereIn('email', $identifiers)->delete()
            + DB::table('notifications')->where('notifiable_type', $morphClass)->where('notifiable_id', $userId)->delete();

        $activities = Activity::query()->where(fn ($query) => $query
            ->where(fn ($subjectQuery) => $subjectQuery->where('subject_type', $morphClass)->where('subject_id', $userId))
            ->orWhere(fn ($causerQuery) => $causerQuery->where('causer_type', $morphClass)->where('causer_id', $userId)));

        foreach ($activities->cursor() as $activity) {
            // Only the user's own model events hold their attributes; a row they caused about a
            // project keeps the project's `name`.
            $isAboutUser = $activity->subject_type === $morphClass && (string) $activity->subject_id === $userId;
            $properties = $this->scrub($activity->properties?->toArray() ?? [], $identifiers, $isAboutUser);
            $attributeChanges = $this->scrub($activity->attribute_changes?->toArray() ?? [], $identifiers, $isAboutUser);

            if ($properties !== ($activity->properties?->toArray() ?? []) || $attributeChanges !== ($activity->attribute_changes?->toArray() ?? [])) {
                // Query builder: saving the activity model would log nothing, but stays explicit.
                DB::table($activity->getTable())->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                    'attribute_changes' => $activity->attribute_changes === null ? null : json_encode($attributeChanges, JSON_THROW_ON_ERROR),
                ]);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Every string that holds an identifier becomes `[erased]`, at any depth, and so does every
     * value of a personal attribute of the user's own model events (an old name the snapshot no
     * longer knows).
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $identifiers
     * @return array<array-key, mixed>
     */
    private function scrub(array $payload, array $identifiers, bool $isAboutUser): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->scrub($value, $identifiers, $isAboutUser);
            } elseif ($isAboutUser && in_array($key, self::PERSONAL_ATTRIBUTES, true) && $value !== null && $value !== self::ERASED_TEXT) {
                $payload[$key] = self::ERASED_TEXT;
            } elseif (is_string($value) && $identifiers !== [] && Str::contains($value, $identifiers, ignoreCase: true)) {
                $payload[$key] = self::ERASED_TEXT;
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
     * person, in one transaction.
     */
    private function close(ErasureRequest $erasureRequest): void
    {
        DB::transaction(function () use ($erasureRequest): void {
            activity()
                ->event(ActivityEventEnum::ErasureCompleted->value)
                ->withProperties([
                    'erasure_request_id' => $erasureRequest->id,
                    'changed' => array_map(fn (array $step): int => $step['changed'] ?? 0, $erasureRequest->steps),
                ])
                ->log('User erased');

            $erasureRequest->forceFill([
                'status' => ErasureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'subject' => null,
                'user_id' => null,
                'last_error_code' => null,
            ])->save();
        });
    }
}
