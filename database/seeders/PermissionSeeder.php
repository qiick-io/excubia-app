<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\SyncPermissionsAction;
use Illuminate\Database\Seeder;

/**
 * Creates every PermissionEnum permission and the built-in roles with their default mapping
 * (see SyncPermissionsAction). Idempotent: safe to run on every deploy.
 */
class PermissionSeeder extends Seeder
{
    public function run(SyncPermissionsAction $syncPermissions): void
    {
        $syncPermissions->execute();
    }
}
