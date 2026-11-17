<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;
use App\Models\User;
use RuntimeException;

/**
 * Rotates a personal API key (#351): same row, uuid, key_prefix, scopes and audit trail; a new
 * secret. The previous full-token hash stays valid until the grace window ends.
 */
final class RotateApiKeyAction
{
    /**
     * @return string the new plain token, shown once
     *
     * @throws RuntimeException when the key is revoked or expired
     */
    public function execute(ApiKey $apiKey, User $actor): string
    {
        if (! $apiKey->isActive()) {
            throw new RuntimeException('Only an active API key can be rotated.');
        }

        $token = ApiKey::generateToken($apiKey->key_prefix);
        $previousHash = $apiKey->key_hash;
        $graceHours = max(1, (int) config('api.key_rotation_grace_hours', 24));

        $apiKey->forceFill([
            'key_hash' => $token['hash'],
            'previous_key_hash' => $previousHash,
            'previous_key_valid_until' => now()->addHours($graceHours),
        ])->saveQuietly();

        activity('api-keys')
            ->causedBy($actor)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyRotated->value)
            ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'key_prefix' => $apiKey->key_prefix])
            ->log('API key rotated');

        return $token['plain'];
    }
}
