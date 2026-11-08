<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\RoleGrantService;
use Spatie\Permission\Models\Role;

/**
 * Custom roles are managed with `can-*-roles`. Built-in roles (RoleEnum) are read-only:
 * PermissionSeeder owns their permissions. A role holding a permission the actor does not hold
 * cannot be edited or deleted by them (#151: nobody strips what they could not grant).
 */
class RolePolicy
{
    public function __construct(private RoleGrantService $roleGrants) {}

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
        return $user->can(PermissionEnum::CanEditRoles->value) && $this->isCustomRoleWithinReach($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(PermissionEnum::CanDeleteRoles->value) && $this->isCustomRoleWithinReach($user, $role);
    }

    private function isCustomRoleWithinReach(User $user, Role $role): bool
    {
        return RoleEnum::tryFrom($role->name) === null
            && $this->roleGrants->canGrantPermissions($user, array_values($role->permissions()->pluck('name')->all()));
    }
}
