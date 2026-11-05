<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * Permissions come from PermissionEnum, so they are only listed and re-synced, with
 * `can-*-roles` (the same people who assign them to roles).
 */
class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::CanShowPermissions->value);
    }

    public function sync(User $user): bool
    {
        return $user->can(PermissionEnum::CanEditPermissions->value);
    }
}
