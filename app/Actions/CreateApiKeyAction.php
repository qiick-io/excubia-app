<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a personal API key for a user (#315) and returns the plain key, which is never stored
 * or logged: the caller shows it once. The model logs the creation (`auth` log, without the hash).
 */
final class CreateApiKeyAction
{
    public function execute(User $user, ApiKeyData $apiKeyData): string
    {
        $plainKey = ApiKey::KEY_PREFIX.Str::random(40);

        $apiKey = new ApiKey([
            'name' => $apiKeyData->name,
            'scopes' => array_map(fn (ApiKeyScopeEnum $scope): string => $scope->value, $apiKeyData->scopes),
            'expires_at' => $apiKeyData->expiresAt,
        ]);
        $apiKey->forceFill([
            'user_id' => $user->id,
            'key_prefix' => substr($plainKey, 0, 12),
            'key_hash' => ApiKey::hashKey($plainKey),
        ])->save();

        return $plainKey;
    }
}
