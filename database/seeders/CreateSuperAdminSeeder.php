<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates a development super-admin from config/super_admin.php, so `migrate --seed` gives a
 * local checkout a login.
 *
 * Only in `local` and `testing`, and only with a password set in `.env` (#225): a server that
 * runs the Laravel reflex `migrate --seed --force` must never get a known-credential account,
 * and `/setup` stays closed once any user exists. Servers create the first admin through
 * `/setup` or `excubia:create-user`.
 *
 * Idempotent on the email: an existing user keeps their password and gets the role,
 * the verified email and the active flag back.
 */
class CreateSuperAdminSeeder extends Seeder
{
    /** The environments where a seeded login is a convenience, not a risk. */
    public const ENVIRONMENTS = ['local', 'testing'];

    public function run(): void
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            $this->command?->warn('Skipped the development super-admin outside local and testing: create the first admin at /setup or with `php artisan excubia:create-user`.');

            return;
        }

        $password = config('super_admin.password');

        if (! is_string($password) || $password === '') {
            $this->command?->warn('Skipped the development super-admin: INITIAL_SUPER_ADMIN_PASSWORD is empty.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => config('super_admin.email')]);

        if (! $user->exists) {
            $user->first_name = config('super_admin.first_name');
            $user->last_name = config('super_admin.last_name');
            $user->password = $password;
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_active' => true])->save();
        $user->assignRole(RoleEnum::SuperAdmin->value);
    }
}
