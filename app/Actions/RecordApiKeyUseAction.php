<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;
use Qiick\Audit\AuditContext;
use RuntimeException;
use Throwable;

/**
 * Logs the first use of a personal API key (#315, #351) in the `auth` log, attributed to the key
 * by qiick/laravel-audit (its public UUID as `api_key_id`, never set by hand). Called by ApiKeyRepositoryService inside the transaction that takes the
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
        } catch (Throwable) {
            // A failed INSERT's QueryException bindings include the request IP from the
            // activity row. The package authenticator reports the exception; never let those
            // bindings reach application logs.
            throw new RuntimeException('Failed to record API key first use in the activity log.');
        } finally {
            config(['activitylog.buffer.enabled' => $isBuffered]);
        }
    }

    private function log(ApiKey $apiKey): void
    {
        // The package attributes the row (api_key_id, prefix, creator) to the bound credential; the
        // authenticator stamps the first use before the middleware binds it, so bind it here too.
        AuditContext::bindCredential($apiKey->auditActor());

        activity()
            ->useLog('auth')
            ->causedBy($apiKey->owner)
            ->performedOn($apiKey)
            ->event(ActivityEventEnum::ApiKeyFirstUsed->value)
            ->withProperties(['name' => $apiKey->name])
            ->log('API key used for the first time');
    }
}
