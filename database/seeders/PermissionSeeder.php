<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every PermissionEnum permission and the built-in roles with their default mapping.
 *
 * Idempotent: safe to run on every deploy. Built-in roles are reset to their defaults
 * (they are read-only in the UI); custom roles are left untouched.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
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
