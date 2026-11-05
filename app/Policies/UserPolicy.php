<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;
use App\Services\SuperAdminGuardService;

/**
 * Users are managed with `can-*-users`. Only a super-admin may manage another super-admin,
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
        return $actor->can(PermissionEnum::CanShowUsers->value);
    }

    /**
     * Whether the actor may search users in a picker: user viewers and group editors.
     */
    public function pick(User $actor): bool
    {
        return $this->viewAny($actor)
            || $actor->can(PermissionEnum::CanCreateGroups->value)
            || $actor->can(PermissionEnum::CanEditGroups->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionEnum::CanCreateUsers->value);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(PermissionEnum::CanEditUsers->value) && $this->canTouch($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can(PermissionEnum::CanDeleteUsers->value)
            && $this->canTouch($actor, $target)
            && ! $actor->is($target)
            && ! $this->superAdminGuard->isLastSuperAdmin($target);
    }

    /** Only a super-admin may change another super-admin. */
    private function canTouch(User $actor, User $target): bool
    {
        return $this->permissionResolver->isSuperAdmin($actor) || ! $this->permissionResolver->isSuperAdmin($target);
    }
}
