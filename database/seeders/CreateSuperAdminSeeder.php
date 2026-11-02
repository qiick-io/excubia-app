<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the initial super-admin from config/super_admin.php.
 *
 * Idempotent on the email: an existing user keeps their password and gets the role,
 * the verified email and the active flag back.
 */
class CreateSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrNew(['email' => config('super_admin.email')]);

        if (! $user->exists) {
            $user->name = config('super_admin.name');
            $user->password = config('super_admin.password');
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_active' => true])->save();
        $user->assignRole(RoleEnum::SuperAdmin->value);
    }
}
