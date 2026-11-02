<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SuperAdminGuardService;

/**
 * Users are managed with `users.manage`. Only a super-admin may manage another super-admin,
 * nobody may delete themselves, and the last active super-admin cannot be deleted.
 */
class UserPolicy
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionEnum::UsersManage->value);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->viewAny($actor)
            && ($actor->hasRole(RoleEnum::SuperAdmin->value) || ! $target->hasRole(RoleEnum::SuperAdmin->value));
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->update($actor, $target)
            && ! $actor->is($target)
            && ! $this->superAdminGuard->isLastSuperAdmin($target);
    }
}
