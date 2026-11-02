<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Role;

/**
 * Creates or updates a custom role and replaces its permissions. The role change is logged by
 * the model, the permissions as a `permissions_synced` activity.
 */
class SaveRoleAction
{
    /**
     * @param  list<string>  $permissions  PermissionEnum values
     */
    public function execute(Role $role, string $name, array $permissions): Role
    {
        $role->name = $name;
        $role->save();
        $role->syncPermissions($permissions);
        $synced = $role->permissions()->orderBy('name')->get(['id', 'name']);

        activity()
            ->performedOn($role)
            ->event('permissions_synced')
            ->withProperties([
                'permission_ids' => $synced->pluck('id')->values()->all(),
                'permission_names' => $synced->pluck('name')->values()->all(),
            ])
            ->log('Role permissions synced');

        return $role;
    }
}
