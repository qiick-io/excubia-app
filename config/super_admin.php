<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Initial super-admin (CreateSuperAdminSeeder)
    |--------------------------------------------------------------------------
    |
    | Used only by `db:seed` to create the first login user. Set a strong
    | password in .env before seeding a server; change it after the first login.
    |
    */

    'name' => env('INITIAL_SUPER_ADMIN_NAME', 'Super Admin'),
    'email' => env('INITIAL_SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
    'password' => env('INITIAL_SUPER_ADMIN_PASSWORD', 'password'),

];
