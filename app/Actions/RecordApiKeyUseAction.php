<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;

/**
 * Stamps last use of a personal API key (#315, #351) at most once a minute, with the client IP.
 * The first use is logged in the `auth` log; later uses only move the stamp so the log does not
 * grow per request.
 */
final class RecordApiKeyUseAction
{
    public function execute(ApiKey $apiKey, ?string $ipAddress = null): void
    {
        if (
            $apiKey->last_used_at !== null
            && $apiKey->last_used_at->greaterThan(now()->subMinute())
        ) {
            return;
        }

        $isFirstUse = $apiKey->last_used_at === null;

        $apiKey->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ipAddress,
        ])->saveQuietly();

        if (! $isFirstUse) {
            return;
        }

        activity()
            ->useLog('auth')
            ->causedBy($apiKey->owner)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyFirstUsed->value)
            ->withProperties(['name' => $apiKey->name, 'key_prefix' => $apiKey->key_prefix])
            ->log('API key used for the first time');
    }
}
