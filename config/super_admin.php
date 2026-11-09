<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Development super-admin (CreateSuperAdminSeeder)
    |--------------------------------------------------------------------------
    |
    | Used only by `db:seed` in `local` and `testing`, and only when a password
    | is set (#225). Servers create the first admin at /setup instead.
    |
    */

    'first_name' => env('INITIAL_SUPER_ADMIN_FIRST_NAME', 'Super'),
    'last_name' => env('INITIAL_SUPER_ADMIN_LAST_NAME', 'Admin'),
    'email' => env('INITIAL_SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
    'password' => env('INITIAL_SUPER_ADMIN_PASSWORD'),

];
