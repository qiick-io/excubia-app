<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;
use App\Services\SuperAdminGuardService;

/**
 * Users are managed with `users.manage`. Only a super-admin may manage another super-admin,
 * nobody may delete themselves, and the last active super-admin cannot be deleted.
 */
class UserPolicy
{
    public function __construct(
        private EffectivePermissionResolverService $permissionResolver,
        private SuperAdminGuardService $superAdminGuard,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::UsersManage->value);
    }

    /**
     * Whether the actor may search users in a picker: user managers and group managers.
     */
    public function pick(User $actor): bool
    {
        return $this->viewAny($actor) || $actor->can(PermissionEnum::GroupsManage->value);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->viewAny($actor)
            && ($this->permissionResolver->isSuperAdmin($actor) || ! $this->permissionResolver->isSuperAdmin($target));
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->update($actor, $target)
            && ! $actor->is($target)
            && ! $this->superAdminGuard->isLastSuperAdmin($target);
    }
}
