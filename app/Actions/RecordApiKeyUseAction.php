<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\UsageSideEffect;

/**
 * Logs the first use of a personal API key (#315, #351) in the `auth` log. qiick/laravel-api-keys
 * stamps `last_used_*` itself (at most once a minute, or on a new IP) and calls this afterwards;
 * later uses only move the stamp, so the log does not grow per request.
 */
final class RecordApiKeyUseAction implements UsageSideEffect
{
    public function afterRecorded(ApiKeyRecord $record, ?string $ip, ?string $userAgent, bool $wasFirstUse): void
    {
        if (! $wasFirstUse || ! $record instanceof ApiKey) {
            return;
        }

        activity()
            ->useLog('auth')
            ->causedBy($record->owner)
            ->performedOn($record)
            ->event(ActivityEventEnum::ApiKeyFirstUsed->value)
            ->withProperties(['name' => $record->name, 'key_prefix' => $record->key_prefix])
            ->log('API key used for the first time');
    }
}
