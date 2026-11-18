<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;
use App\Models\User;

/**
 * Revokes a personal API key (#315, #351): the next request with it is rejected immediately. The
 * row stays for the list and the audit trail; any rotation grace window is cleared.
 */
final class RevokeApiKeyAction
{
    public function execute(ApiKey $apiKey, ?User $actor = null): void
    {
        if ($apiKey->revoked_at !== null) {
            return;
        }

        $apiKey->forceFill([
            'revoked_at' => now(),
            'previous_key_hash' => null,
            'previous_key_valid_until' => null,
        ])->saveQuietly();

        $activity = activity('api-keys')
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyRevoked->value)
            ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'key_prefix' => $apiKey->key_prefix]);

        if ($actor !== null) {
            $activity->causedBy($actor);
        }

        $activity->log('API key revoked');
    }
}
