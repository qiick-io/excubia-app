<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\RoleGrantService;
use App\Services\SuperAdminGuardService;

/**
 * Users are managed with `can-*-users`, but only users whose roles the actor could grant
 * (RoleGrantService, #151): nobody edits, resets or deletes a more powerful account, so only a
 * super-admin manages super-admins. Nobody may delete themselves, and the last active
 * super-admin cannot be deleted.
 */
class UserPolicy
{
    public function __construct(
        private RoleGrantService $roleGrants,
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

    /** The target holds no role the actor could not grant (super-admins pass). */
    private function canTouch(User $actor, User $target): bool
    {
        return $this->roleGrants->isAtOrBelow($actor, $target);
    }
}
