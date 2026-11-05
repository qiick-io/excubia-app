<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Custom roles are managed with `can-*-roles`. Built-in roles (RoleEnum) are read-only:
 * PermissionSeeder owns their permissions.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::CanShowRoles->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionEnum::CanCreateRoles->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(PermissionEnum::CanEditRoles->value) && RoleEnum::tryFrom($role->name) === null;
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(PermissionEnum::CanDeleteRoles->value) && RoleEnum::tryFrom($role->name) === null;
    }
}
