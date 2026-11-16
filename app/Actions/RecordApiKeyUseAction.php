<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;

/**
 * Stamps the last use of a personal API key (#315). The first use is logged in the `auth` log,
 * caused by the owner; later uses only move `last_used_at`, so the log does not grow per request.
 */
final class RecordApiKeyUseAction
{
    public function execute(ApiKey $apiKey): void
    {
        $isFirstUse = $apiKey->last_used_at === null;

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        if (! $isFirstUse) {
            return;
        }

        activity()
            ->useLog('auth')
            ->causedBy($apiKey->user)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyFirstUsed->value)
            ->withProperties(['name' => $apiKey->name, 'key_prefix' => $apiKey->key_prefix])
            ->log('API key used for the first time');
    }
}
