<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Deletes a dashboard user. Roles and passkeys go with the row (cascade).
 */
class DeleteUserAction
{
    public function execute(User $user): void
    {
        $user->delete();
    }
}
