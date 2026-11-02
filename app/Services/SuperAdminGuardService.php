<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;

/**
 * Keeps at least one active super-admin, so the instance never locks itself out.
 *
 * Callers check it before removing the super-admin role from a user, deactivating them
 * or deleting them.
 */
class SuperAdminGuardService
{
    /**
     * Whether the user is the only active super-admin left.
     */
    public function isLastSuperAdmin(User $user): bool
    {
        if (! $user->is_active || ! $user->hasRole(RoleEnum::SuperAdmin->value)) {
            return false;
        }

        return User::query()
            ->role(RoleEnum::SuperAdmin->value)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
