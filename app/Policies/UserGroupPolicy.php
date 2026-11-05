<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;

/**
 * Groups are managed with `can-*-groups`. A group that carries the super-admin role is
 * managed by super-admins only, so nobody can join it to promote themselves.
 */
class UserGroupPolicy
{
    public function __construct(private EffectivePermissionResolverService $permissionResolver) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanShowGroups->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanCreateGroups->value);
    }

    public function update(User $actor, UserGroup $group): bool
    {
        return $actor->can(PermissionEnum::CanEditGroups->value) && $this->canTouch($actor, $group);
    }

    public function delete(User $actor, UserGroup $group): bool
    {
        return $actor->can(PermissionEnum::CanDeleteGroups->value) && $this->canTouch($actor, $group);
    }

    /** Only a super-admin may change a group that carries the super-admin role. */
    private function canTouch(User $actor, UserGroup $group): bool
    {
        return $this->permissionResolver->isSuperAdmin($actor)
            || $group->roles()->where('name', RoleEnum::SuperAdmin->value)->doesntExist();
    }
}
