<?php

declare(strict_types=1);

namespace App\Services;

use Database\Seeders\CreateSuperAdminSeeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The one password gate for the initial super admin, shared by `db:seed` and `excubia:create-user`.
 *
 * Local and testing keep the convenience password from .env, including the `password` example.
 * Everywhere else a known credential must never bootstrap an install: the password has to be set,
 * differ from `password` regardless of case and satisfy the configured Password::defaults().
 * The reasons are plain messages so both surfaces report exactly why a password was refused.
 */
class InitialSuperAdminPasswordService
{
    /**
     * @return list<string> why the password is refused; empty when it is allowed
     */
    public function rejectionReasons(?string $password, ?string $environment = null): array
    {
        $environment ??= app()->environment();

        if ($password === null || $password === '') {
            return ['The password is not set'];
        }

        if (in_array($environment, CreateSuperAdminSeeder::ENVIRONMENTS, true)) {
            return [];
        }

        $reasons = [];

        if (strcasecmp($password, 'password') === 0) {
            $reasons[] = 'The password must not be the known default "password"';
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        if ($validator->fails()) {
            $reasons[] = 'The password does not meet the password policy: '.implode(' ', $validator->errors()->all());
        }

        return $reasons;
    }
}
