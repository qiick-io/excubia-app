<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Effective roles and permissions of a user, from qiick/laravel-access: direct roles and
 * permissions plus the roles of the user's active groups (soft-deleted groups and expired
 * memberships grant nothing). Super-admin holds every permission, but only through a direct
 * role or an instance group.
 *
 * Actor-aware: under an API actor (a personal API key, bound by AuthenticateApiKeyMiddleware)
 * a policy sees only the owner's assigned permissions, read fresh, and never the super-admin
 * grant (qiick/laravel-access design §6), the same ceiling the key authorizer applies. Gate::before
 * already abstains for API actors; this keeps the policies and the project filter in line.
 *
 * Excubia's own question stays here: {@see isAdmin()}, who may see and restrict any project.
 */
class EffectivePermissionResolverService
{
    public function __construct(
        private readonly EffectivePermissionResolver $resolver,
        private readonly ActorResolver $actors,
    ) {}

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

    /**
     * Never under an API actor: a key does not carry its owner's super-admin grant.
     */
    public function isSuperAdmin(User $user): bool
    {
        return ! $this->isApiActor() && $this->resolver->isSuperAdmin($user);
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
        return $this->isApiActor()
            ? $this->hasAssignedPermission($user, $permission)
            : $this->resolver->hasPermission($user, $permission);
    }

    /**
     * The owner ceiling behind an API key: assigned permissions only, never the super-admin
     * grant, read fresh on every request (qiick/laravel-access design §6).
     */
    public function hasAssignedPermission(User $user, string $permission): bool
    {
        return $this->resolver->hasAssignedPermission($user, $permission, fresh: true);
    }

    private function isApiActor(): bool
    {
        return $this->actors->current()->isApiActor();
    }
}
