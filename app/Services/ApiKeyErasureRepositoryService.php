<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\ErasureRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\ApiKeys\Contracts\ApiKeyErasureRepositoryContract;
use Qiick\ApiKeys\Erasure\ApiKeysEraser;
use Qiick\ApiKeys\RevocationReason;
use SensitiveParameter;

/**
 * Excubia's `api_keys` persistence for qiick/laravel-api-keys' ApiKeysEraser, called only by
 * ErasureOrchestratorService.
 *
 * Owned keys are the ones whose owner is the user, or whose owner principal or key UUID is in the
 * trusted snapshot. `anonymise` (Excubia's mode) keeps them as inert rows: revoked, their token
 * material replaced, a new UUID, every free-text, usage and restriction column cleared. They stay
 * on the user's tombstone row and erased principal, which can never authenticate again (the FKs
 * are RESTRICT, so audit links survive). `delete` removes them. Other people's keys lose the
 * person's attribution (`created_by`, `revoked_by`) and any identifier in their free text.
 * Query-builder writes only: no model event and no activity entry carries the old values.
 */
class ApiKeyErasureRepositoryService implements ApiKeyErasureRepositoryContract
{
    private const ERASED_TEXT = '[erased]';

    public function connection(): Connection
    {
        return DB::connection((new ApiKey)->getConnectionName());
    }

    /**
     * @return array<string, int|string>
     */
    public function keyIdsFromSnapshot(#[SensitiveParameter] string $userPublicId): array
    {
        $openRequests = ErasureRequest::query()
            ->whereIn('status', [ErasureRequest::STATUS_PENDING, ErasureRequest::STATUS_RUNNING, ErasureRequest::STATUS_FAILED])
            ->whereNotNull('subject')
            ->get();

        foreach ($openRequests as $openRequest) {
            try {
                $subject = $openRequest->readSubject();
            } catch (DecryptException) {
                continue;
            }

            if ($subject !== null && $subject['userPublicId'] === $userPublicId) {
                return $subject['apiKeyIdsByPublicId'];
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $publicIds
     * @param  list<string>  $identifiers
     * @return array{deleted: int, anonymised: int, keyIdsByPublicId: array<string, int|string>}
     */
    public function eraseForUser(
        string $userId,
        #[SensitiveParameter] string $userPublicId,
        string $mode,
        #[SensitiveParameter] array $publicIds,
        #[SensitiveParameter] array $identifiers,
        bool $isDryRun,
    ): array {
        $connection = $this->connection();
        $ownedKeys = fn (): Builder => $connection->table('api_keys')->where(fn (Builder $keys) => $keys
            ->where('owner_id', $userId)
            ->orWhereIn('owner_principal_id', $publicIds)
            ->orWhereIn('uuid', $publicIds));
        /** @var array<string, int|string> $keyIdsByPublicId */
        $keyIdsByPublicId = $ownedKeys()->pluck('id', 'uuid')->all();
        $ownedIds = array_values($keyIdsByPublicId);
        $ownedToChange = $mode === ApiKeysEraser::DELETE ? count($ownedIds) : $ownedKeys()->where('name', '!=', self::ERASED_TEXT)->count();
        $attributed = $connection->table('api_keys')->whereNotIn('id', $ownedIds)
            ->where(fn (Builder $keys) => $keys->where('created_by', $userId)->orWhere('revoked_by', $userId))
            ->pluck('id')
            ->all();
        $textMatches = $this->freeTextMatches($connection, $ownedIds, $identifiers);
        $anonymisedOthers = count(array_unique([...$attributed, ...array_keys($textMatches)]));

        if ($isDryRun) {
            return [
                'deleted' => $mode === ApiKeysEraser::DELETE ? $ownedToChange : 0,
                'anonymised' => ($mode === ApiKeysEraser::DELETE ? 0 : $ownedToChange) + $anonymisedOthers,
                'keyIdsByPublicId' => $keyIdsByPublicId,
            ];
        }

        $connection->table('api_keys')->whereIn('id', $attributed)->where('created_by', $userId)->update(['created_by' => null]);
        $connection->table('api_keys')->whereIn('id', $attributed)->where('revoked_by', $userId)->update(['revoked_by' => null]);

        foreach ($textMatches as $keyId => $scrubbedColumns) {
            $connection->table('api_keys')->where('id', $keyId)->update($scrubbedColumns);
        }

        if ($mode === ApiKeysEraser::DELETE) {
            $connection->table('api_keys')->whereIn('id', $ownedIds)->delete();

            return ['deleted' => $ownedToChange, 'anonymised' => $anonymisedOthers, 'keyIdsByPublicId' => $keyIdsByPublicId];
        }

        // Row by row: each key gets its own new UUID and unusable hash (both are unique).
        foreach ($ownedKeys()->where('name', '!=', self::ERASED_TEXT)->pluck('id') as $ownedId) {
            $connection->table('api_keys')->where('id', $ownedId)->update([
                'uuid' => (string) Str::uuid7(),
                'name' => self::ERASED_TEXT,
                'description' => null,
                'key_hash' => hash('sha256', Str::random(64)),
                'previous_key_prefix' => null,
                'previous_key_hash' => null,
                'previous_key_valid_until' => null,
                'created_by' => null,
                'ip_allowlist' => null,
                'restrictions' => null,
                'metadata' => null,
                'expiry_notice_claimed_at' => null,
                'expiry_notice_claim_token' => null,
                'last_used_ip' => null,
                'last_used_user_agent' => null,
                'exposure_source' => null,
                'revoked_at' => $connection->table('api_keys')->where('id', $ownedId)->value('revoked_at') ?? now(),
                'revoked_reason' => RevocationReason::Owner->value,
                'revoked_by' => null,
                'revocation_note' => null,
            ]);
        }

        return ['deleted' => 0, 'anonymised' => $ownedToChange + $anonymisedOthers, 'keyIdsByPublicId' => $keyIdsByPublicId];
    }

    /**
     * Other people's keys whose name, description or revocation note holds an identifier, with
     * the scrubbed values to write.
     *
     * @param  list<int|string>  $ownedIds
     * @param  list<string>  $identifiers
     * @return array<int|string, array<string, string>>
     */
    private function freeTextMatches(Connection $connection, array $ownedIds, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $matches = [];
        $keys = $connection->table('api_keys')->whereNotIn('id', $ownedIds)->get(['id', 'name', 'description', 'revocation_note']);

        foreach ($keys as $key) {
            $scrubbedColumns = [];

            foreach (['name', 'description', 'revocation_note'] as $column) {
                $value = $key->{$column};

                if (is_string($value) && Str::contains($value, $identifiers, ignoreCase: true)) {
                    $scrubbedColumns[$column] = str_ireplace($identifiers, self::ERASED_TEXT, $value);
                }
            }

            if ($scrubbedColumns !== []) {
                $matches[$key->id] = $scrubbedColumns;
            }
        }

        return $matches;
    }
}
