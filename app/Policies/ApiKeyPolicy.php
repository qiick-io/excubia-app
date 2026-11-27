<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Personal API keys (#315, #351) belong to one owner: only they may rotate or revoke. Not even a
 * super-admin manages another user's key here; deactivating or deleting the owner stops all their
 * keys. Key management is session (`web`) only — never over the `api_key` guard.
 */
class ApiKeyPolicy
{
    public function update(User $actor, ApiKey $apiKey): bool
    {
        return $apiKey->owner_id === $actor->id;
    }

    public function delete(User $actor, ApiKey $apiKey): bool
    {
        return $apiKey->owner_id === $actor->id;
    }
}
