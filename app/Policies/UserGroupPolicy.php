<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;
use App\Services\RoleGrantService;

/**
 * Groups are managed with `can-*-groups`, but only groups whose roles the actor could grant
 * (RoleGrantService, #151): a group that carries `super-admin` is managed by super-admins only,
 * one that carries `admin` by admins, so nobody adds members to a role they do not hold or
 * strips it from them.
 */
class UserGroupPolicy
{
    public function __construct(
        private RoleGrantService $roleGrants,
        private EffectivePermissionResolverService $permissions,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanShowGroups->value);
    }

    public function create(User $actor): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanCreateGroups->value);
    }

    public function update(User $actor, UserGroup $group): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanEditGroups->value) && $this->canTouch($actor, $group);
    }

    public function delete(User $actor, UserGroup $group): bool
    {
        return $this->permissions->hasPermission($actor, PermissionEnum::CanDeleteGroups->value) && $this->canTouch($actor, $group);
    }

    /** The group carries no role the actor could not grant (super-admins pass). */
    private function canTouch(User $actor, UserGroup $group): bool
    {
        return $this->roleGrants->canGrantRoles($actor, array_values($group->roles()->pluck('name')->all()));
    }
}
