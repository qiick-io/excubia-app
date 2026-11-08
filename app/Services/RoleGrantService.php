<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * "You can only grant what you hold" (#151). A super-admin grants anything. Anyone else may
 * grant a permission they hold, and a role whose permissions they all hold, with two
 * exceptions: `super-admin` is granted only by super-admins, and `admin` only by holders of the
 * `admin` role, because both carry more than their permissions (super-admin passes every check,
 * admin sees the projects restricted to admins).
 *
 * The same rule decides who may manage whom: users and groups whose roles the actor could not
 * grant are "above" the actor and cannot be edited or deleted by them, so nobody strips or
 * takes over a more powerful account.
 *
 * ponytail: no cache, a few small queries per check (see EffectivePermissionResolverService).
 */
class RoleGrantService
{
    public function __construct(private EffectivePermissionResolverService $permissionResolver) {}

    /**
     * Role names the actor may grant, in name order.
     *
     * @return list<string>
     */
    public function grantableRoleNames(User $actor): array
    {
        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get();

        if ($this->permissionResolver->isSuperAdmin($actor)) {
            return array_values($roles->pluck('name')->all());
        }

        $heldRoles = $this->permissionResolver->roleNamesFor($actor);
        $heldPermissions = $this->permissionResolver->permissionsFor($actor);

        return array_values($roles
            ->filter(fn (Role $role): bool => match ($role->name) {
                RoleEnum::SuperAdmin->value => false,
                RoleEnum::Admin->value => in_array(RoleEnum::Admin->value, $heldRoles, true),
                default => $role->permissions->every(fn (Permission $permission): bool => in_array($permission->name, $heldPermissions, true)),
            })
            ->pluck('name')
            ->all());
    }

    /**
     * Whether the actor may grant (or take away) every one of these roles.
     *
     * @param  list<string>  $roleNames
     */
    public function canGrantRoles(User $actor, array $roleNames): bool
    {
        return array_diff($roleNames, $this->grantableRoleNames($actor)) === [];
    }

    /**
     * Whether the actor holds every one of these permissions (super-admins hold all).
     *
     * @param  list<string>  $permissionNames
     */
    public function canGrantPermissions(User $actor, array $permissionNames): bool
    {
        return array_diff($permissionNames, $this->permissionResolver->permissionsFor($actor)) === [];
    }

    /**
     * Whether the actor could grant every role the target holds, directly or through a group.
     */
    public function isAtOrBelow(User $actor, User $target): bool
    {
        return $this->canGrantRoles($actor, $this->permissionResolver->roleNamesFor($target));
    }
}
