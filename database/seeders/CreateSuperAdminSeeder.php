<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\CreateUserAction;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Illuminate\Database\Seeder;

/**
 * Creates the initial super-admin from config/super_admin.php, so a fresh install can sign in.
 *
 * The password gate is shared with `excubia:create-user` (#225): local and testing use the
 * password from .env; a server creates the admin only when that password is set, is not the known
 * default and meets Password::defaults(). A skipped seed always says which condition failed.
 *
 * Never rewrites an existing user: re-seeding or upgrading must not reset an admin's password,
 * activation, verification or roles.
 */
class CreateSuperAdminSeeder extends Seeder
{
    /** The environments where a seeded login is a convenience, not a risk. */
    public const ENVIRONMENTS = ['local', 'testing'];

    public function run(InitialSuperAdminPasswordService $initialSuperAdminPassword, CreateUserAction $createUser): void
    {
        $configuredPassword = config('super_admin.password');
        $password = is_string($configuredPassword) ? $configuredPassword : null;

        $reasons = $initialSuperAdminPassword->rejectionReasons($password);

        if ($reasons !== []) {
            $this->command?->warn('Skipped the initial super-admin (INITIAL_SUPER_ADMIN_PASSWORD): '.implode('; ', $reasons));

            return;
        }

        if (User::query()->where('email', config('super_admin.email'))->exists()) {
            return;
        }

        $lastName = config('super_admin.last_name');

        // A trusted system write (no actor), through the same role-write path as every other.
        $createUser->execute(
            null,
            (string) config('super_admin.first_name'),
            is_string($lastName) && $lastName !== '' ? $lastName : null,
            (string) config('super_admin.email'),
            null,
            (string) $password,
            [RoleEnum::SuperAdmin->value],
        );
    }
}
