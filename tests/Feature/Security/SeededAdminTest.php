<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\CreateSuperAdminSeeder;

/**
 * The development super-admin (#225): `db:seed` never creates a known-credential account on a
 * server, and never one without a password set in `.env`.
 */
beforeEach(function () {
    config([
        'super_admin.email' => 'superadmin@example.com',
        'super_admin.password' => 'password',
    ]);
});

it('creates no user when db:seed runs in production with the example values', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(0);
})->with(['production', 'staging']);

it('creates no user when the seeder is run explicitly in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('Skipped the development super-admin')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
});

it('creates no user locally while INITIAL_SUPER_ADMIN_PASSWORD is empty', function (?string $password) {
    config(['super_admin.password' => $password]);

    $this->artisan('db:seed')->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
})->with(['empty' => [''], 'unset' => [null]]);

it('ships .env.example without a working seed password', function () {
    expect(file_get_contents(base_path('.env.example')))->toMatch('/^INITIAL_SUPER_ADMIN_PASSWORD=$/m');
});
