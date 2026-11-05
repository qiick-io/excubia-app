<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed permissions, built-in roles, the initial super-admin and the system project.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CreateSuperAdminSeeder::class,
            SystemProjectSeeder::class,
        ]);
    }
}
