<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed permissions, built-in roles and the initial super-admin.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CreateSuperAdminSeeder::class,
        ]);
    }
}
