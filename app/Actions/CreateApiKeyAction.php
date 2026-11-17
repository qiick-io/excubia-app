<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApiKeyData;
use App\Enums\ActivityEventEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a personal API key (#315, #351) for an owner and returns the plain token once. The hash
 * of the full token is stored; the plain value is never logged or written to the activity log.
 */
final class CreateApiKeyAction
{
    /**
     * @return string the plain token, shown once to the caller
     */
    public function execute(User $owner, ApiKeyData $apiKeyData, ?User $creator = null): string
    {
        $creator ??= $owner;
        $token = ApiKey::generateToken();
        $scopes = array_map(
            fn (ApiKeyScopeEnum $scope): string => $scope->value,
            ApiKeyScopeEnum::normalize($apiKeyData->scopes),
        );

        $apiKey = new ApiKey([
            'name' => $apiKeyData->name,
            'scopes' => $scopes,
            'ip_allowlist' => $apiKeyData->ipAllowlist,
            'rate_limit_per_minute' => $apiKeyData->rateLimitPerMinute,
            'expires_at' => $apiKeyData->expiresAt,
        ]);
        $apiKey->forceFill([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'created_by' => $creator->id,
            'key_prefix' => $token['prefix'],
            'key_hash' => $token['hash'],
        ])->saveQuietly();

        activity('api-keys')
            ->causedBy($creator)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::Created->value)
            ->withProperties(['name' => $apiKey->name, 'scopes' => $scopes, 'key_prefix' => $apiKey->key_prefix])
            ->log('API key created');

        return $token['plain'];
    }
}
