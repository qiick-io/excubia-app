<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Personal API keys (#315) belong to one user: only they may revoke one. Not even a super-admin
 * revokes another user's key here; deactivating or deleting the user stops all their keys.
 */
class ApiKeyPolicy
{
    public function delete(User $actor, ApiKey $apiKey): bool
    {
        return $apiKey->user_id === $actor->id;
    }
}
