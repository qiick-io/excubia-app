<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RecordApiKeyUseAction;
use App\Models\ApiKey;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\ApiKeyRepository;
use Qiick\ApiKeys\CredentialKind;
use Throwable;

/**
 * Excubia's storage behind qiick/laravel-api-keys: finds keys for the authenticator, stamps their
 * use, and claims expiry notices for `api-keys:notify-expiring`.
 *
 * Every write is a conditional query-builder update, never a model save: usage stamps must not
 * write model activity, and the first-use stamp and the expiry claim are compare-and-sets so two
 * requests or workers cannot both win them.
 */
class ApiKeyRepositoryService implements ApiKeyRepository
{
    public function __construct(private readonly RecordApiKeyUseAction $recordApiKeyUse) {}

    /**
     * The row with this prefix, or the row still inside its rotation grace under its old prefix.
     *
     * @return list<ApiKey>
     */
    public function findCandidatesByPrefix(#[\SensitiveParameter] string $keyPrefix): iterable
    {
        return array_values(ApiKey::query()
            ->with(['owner', 'ownerPrincipal'])
            ->where('key_prefix', $keyPrefix)
            ->orWhere(fn ($query) => $query
                ->where('previous_key_prefix', $keyPrefix)
                ->where('previous_key_valid_until', '>', now()))
            ->get()
            ->all());
    }

    public function findByPublicId(string $publicId): ?ApiKey
    {
        return ApiKey::query()->where('uuid', $publicId)->first();
    }

    /**
     * Stamps the use. The first use and its `auth` activity are one transition: only the request
     * whose conditional update takes the stamp from null logs it, and a failed audit write rolls
     * the stamp back, so the next request logs the first use instead of skipping it forever.
     *
     * @throws Throwable when the audit entry cannot be written (the package reports it and the
     *                   request still authenticates)
     */
    public function recordUsage(ApiKeyRecord $record, ?string $ip, ?string $userAgent, DateTimeImmutable $usedAt): void
    {
        $stamp = [
            'last_used_at' => $usedAt,
            'last_used_ip' => $ip,
            'last_used_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
        ];

        DB::transaction(function () use ($record, $stamp): void {
            $isFirstUse = ApiKey::query()->whereKey($record->id())->whereNull('last_used_at')->toBase()->update($stamp) === 1;

            if (! $isFirstUse) {
                ApiKey::query()->whereKey($record->id())->toBase()->update($stamp);

                return;
            }

            if ($record instanceof ApiKey) {
                $this->recordApiKeyUse->execute($record);
            }
        });
    }

    /**
     * @return list<ApiKey>
     */
    public function expiringUnnotified(DateTimeImmutable $until): iterable
    {
        return array_values(ApiKey::query()
            ->with(['owner', 'creator'])
            ->where('credential_kind', CredentialKind::ApiKey->value)
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $until)
            ->get()
            ->all());
    }

    public function markExpiryNotified(ApiKeyRecord $record, DateTimeImmutable $notifiedAt): void
    {
        ApiKey::query()
            ->where('uuid', $record->publicId())
            ->whereNull('expiry_notified_at')
            ->toBase()
            ->update(['expiry_notified_at' => $notifiedAt, 'expiry_notice_claimed_at' => null]);
    }

    /**
     * Wins only when the notice is unsent and unclaimed, or its lease (app clock) has lapsed.
     */
    public function claimExpiryNotice(ApiKeyRecord $record, DateTimeImmutable $now, int $leaseSeconds): bool
    {
        $leaseCutoff = $now->modify('-'.$leaseSeconds.' seconds');

        return ApiKey::query()
            ->where('uuid', $record->publicId())
            ->whereNull('expiry_notified_at')
            ->where(fn ($query) => $query
                ->whereNull('expiry_notice_claimed_at')
                ->orWhere('expiry_notice_claimed_at', '<', $leaseCutoff))
            ->toBase()
            ->update(['expiry_notice_claimed_at' => $now]) === 1;
    }

    public function releaseExpiryNotice(ApiKeyRecord $record): void
    {
        ApiKey::query()
            ->where('uuid', $record->publicId())
            ->whereNull('expiry_notified_at')
            ->toBase()
            ->update(['expiry_notice_claimed_at' => null]);
    }

    /**
     * Excubia never issued a legacy token format, so there is nothing to warn about.
     *
     * @return list<ApiKey>
     */
    public function findActiveLegacy(): iterable
    {
        return [];
    }
}
