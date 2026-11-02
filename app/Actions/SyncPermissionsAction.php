<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every PermissionEnum permission and resets the built-in roles to their default
 * mapping. Run by PermissionSeeder on deploy and by the "Sync" button on the permissions page.
 *
 * Idempotent: built-in roles are read-only in the UI, so resetting them loses nothing;
 * custom roles are left untouched. Stale permissions are not pruned.
 */
class SyncPermissionsAction
{
    public function execute(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value);
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            Role::findOrCreate($roleEnum->value)->syncPermissions(
                array_map(fn (PermissionEnum $permission): string => $permission->value, $roleEnum->defaultPermissions()),
            );
        }
    }
}
