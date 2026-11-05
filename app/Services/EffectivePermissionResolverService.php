<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Effective roles and permissions of a user: direct roles and permissions plus the roles of
 * the user's groups. Super-admin holds every permission.
 *
 * ponytail: no cache, a few small queries per check. Add a per-request cache if it shows up
 * in profiles.
 */
class EffectivePermissionResolverService
{
    /**
     * @return list<string>
     */
    public function roleNamesFor(User $user): array
    {
        return array_values(Role::query()
            ->where(fn ($query) => $query
                ->whereHas('users', fn ($users) => $users->whereKey($user->getKey()))
                ->orWhereIn('id', fn ($groupRoles) => $groupRoles
                    ->select('user_group_role.role_id')
                    ->from('user_group_role')
                    ->join('user_group_user', 'user_group_user.user_group_id', '=', 'user_group_role.user_group_id')
                    ->where('user_group_user.user_id', $user->getKey())))
            ->orderBy('name')
            ->pluck('name')
            ->all());
    }

    /**
     * Admin or super-admin, directly or through a group (who may see and restrict any project).
     */
    public function isAdmin(User $user): bool
    {
        return array_intersect([RoleEnum::SuperAdmin->value, RoleEnum::Admin->value], $this->roleNamesFor($user)) !== [];
    }

    public function isSuperAdmin(User $user): bool
    {
        return in_array(RoleEnum::SuperAdmin->value, $this->roleNamesFor($user), true);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(User $user): array
    {
        $roleNames = $this->roleNamesFor($user);

        if (in_array(RoleEnum::SuperAdmin->value, $roleNames, true)) {
            return PermissionEnum::values();
        }

        return array_values(Permission::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->whereIn('name', $roleNames))
                ->orWhereHas('users', fn ($users) => $users->whereKey($user->getKey())))
            ->orderBy('name')
            ->pluck('name')
            ->all());
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }
}
