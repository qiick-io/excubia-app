<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;

/**
 * Revokes a personal API key (#315): the next request with it is rejected. The row stays, so the
 * list and the activity log still name it.
 */
final class RevokeApiKeyAction
{
    public function execute(ApiKey $apiKey): void
    {
        if ($apiKey->revoked_at !== null) {
            return;
        }

        // Quietly: the model would log a generic "updated"; this explicit event says what happened.
        $apiKey->forceFill(['revoked_at' => now()])->saveQuietly();

        activity()
            ->useLog('auth')
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyRevoked->value)
            ->withProperties(['name' => $apiKey->name, 'key_prefix' => $apiKey->key_prefix])
            ->log('API key revoked');
    }
}
