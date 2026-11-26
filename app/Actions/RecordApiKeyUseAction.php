<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;
use Qiick\ApiKeys\Audit;

/**
 * Logs the first use of a personal API key (#315, #351) in the `auth` log, with the key's public
 * UUID as `api_key_id`. Called by ApiKeyRepositoryService inside the transaction that takes the
 * first-use stamp, so the entry and the stamp are written together or not at all: the entry is
 * always inserted at once, even when the activity buffer is on.
 */
final class RecordApiKeyUseAction
{
    public function execute(ApiKey $apiKey): void
    {
        // Spatie's buffer would insert the row after the response, outside the stamp's
        // transaction: a lost flush would leave the stamp set and the first use unlogged forever.
        $isBuffered = config('activitylog.buffer.enabled');
        config(['activitylog.buffer.enabled' => false]);

        try {
            $this->log($apiKey);
        } finally {
            config(['activitylog.buffer.enabled' => $isBuffered]);
        }
    }

    private function log(ApiKey $apiKey): void
    {
        activity()
            ->useLog('auth')
            ->causedBy($apiKey->owner)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyFirstUsed->value)
            ->withProperties(Audit::withApiKeyId(['name' => $apiKey->name, 'key_prefix' => $apiKey->key_prefix], $apiKey->uuid))
            ->log('API key used for the first time');
    }
}
