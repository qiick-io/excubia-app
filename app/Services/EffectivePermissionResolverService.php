<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Effective roles and permissions of a user, from qiick/laravel-access: direct roles and
 * permissions plus the roles of the user's active groups (soft-deleted groups and expired
 * memberships grant nothing). Super-admin holds every permission, but only through a direct
 * role or an instance group.
 *
 * Excubia's own question stays here: {@see isAdmin()}, who may see and restrict any project.
 */
class EffectivePermissionResolverService
{
    public function __construct(private readonly EffectivePermissionResolver $resolver) {}

    /**
     * @return list<string>
     */
    public function roleNamesFor(User $user): array
    {
        return $this->resolver->roleNamesFor($user);
    }

    /**
     * Admin or super-admin, directly or through a group.
     */
    public function isAdmin(User $user): bool
    {
        return $this->isSuperAdmin($user) || in_array(RoleEnum::Admin->value, $this->roleNamesFor($user), true);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->resolver->isSuperAdmin($user);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(User $user): array
    {
        return $this->resolver->permissionsFor($user);
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->resolver->hasPermission($user, $permission);
    }

    /**
     * The owner ceiling behind an API key: assigned permissions only, never the super-admin
     * grant, read fresh on every request (qiick/laravel-access design §6).
     */
    public function hasAssignedPermission(User $user, string $permission): bool
    {
        return $this->resolver->hasAssignedPermission($user, $permission, fresh: true);
    }
}
