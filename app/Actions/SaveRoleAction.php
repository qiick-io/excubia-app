<?php

declare(strict_types=1);

namespace App\Actions;

use Spatie\Permission\Models\Role;

/**
 * Creates or updates a custom role and replaces its permissions.
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

        return $role;
    }
}
