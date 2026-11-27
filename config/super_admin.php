<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Initial super-admin (CreateSuperAdminSeeder / excubia:create-user)
    |--------------------------------------------------------------------------
    |
    | Used by `db:seed`: in `local` and `testing` the password comes from .env, where
    | `password` is only an example. On a server the admin is created only when that
    | password is set, is not `password` and passes Password::defaults(); otherwise
    | the seed warns why and skips. An existing user is never rewritten.
    |
    */

    'first_name' => env('INITIAL_SUPER_ADMIN_FIRST_NAME', 'Super'),
    'last_name' => env('INITIAL_SUPER_ADMIN_LAST_NAME', 'Admin'),
    'email' => env('INITIAL_SUPER_ADMIN_EMAIL', 'superadmin@example.com'),
    'password' => env('INITIAL_SUPER_ADMIN_PASSWORD'),

];
