<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\ApiKey;
use App\Models\User;
use Qiick\ApiKeys\RevocationReason;

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

        // The owner revokes from Settings; anyone else (an admin, a job) is recorded apart.
        $revokedReason = match (true) {
            $actor === null => RevocationReason::System,
            $actor->id === $apiKey->owner_id => RevocationReason::User,
            default => RevocationReason::Admin,
        };

        $apiKey->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor?->id,
            'revoked_reason' => $revokedReason->value,
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
