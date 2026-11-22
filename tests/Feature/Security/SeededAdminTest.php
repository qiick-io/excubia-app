<?php

declare(strict_types=1);

use App\Actions\DeleteUserAction;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * The initial super-admin follows one fleet rule: in local and testing the password comes from
 * .env (the example default is `password`); on a server the admin is created only when that
 * password is set, differs from the known default and meets the password policy. An existing
 * account is never rewritten by a seed.
 */
beforeEach(function () {
    Http::fake();

    $this->seed(PermissionSeeder::class);

    config([
        'super_admin.first_name' => 'Super',
        'super_admin.last_name' => 'Admin',
        'super_admin.email' => 'superadmin@example.com',
        'super_admin.password' => 'password',
    ]);
});

/**
 * Run the assertions as a server with the default strong password policy.
 */
function configureInitialSuperAdminServer(): void
{
    app()['env'] = 'production';
    app(SettingsRepositoryService::class)->save(['password_policy' => 'strong']);
}

it('creates a verified active super admin in production with a strong password', function () {
    configureInitialSuperAdminServer();
    config(['super_admin.password' => 'V3ry-Str0ng-Passphrase-2026']);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->email)->toBe('superadmin@example.com')
        ->and($user->first_name)->toBe('Super')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and(Hash::check('V3ry-Str0ng-Passphrase-2026', $user->password))->toBeTrue();
});

it('skips the seeded super admin in production when the password is the known default', function (string $password) {
    configureInitialSuperAdminServer();
    config(['super_admin.password' => $password]);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('must not be the known default')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
})->with(['password', 'PASSWORD']);

it('skips the seeded super admin in production when the password is weak', function () {
    configureInitialSuperAdminServer();
    config(['super_admin.password' => 'short']);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('does not meet the password policy')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
});

it('skips the seeded super admin in production when the password is empty', function (?string $password) {
    configureInitialSuperAdminServer();
    config(['super_admin.password' => $password]);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('is not set')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
})->with(['empty' => '', 'unset' => null]);

it('leaves an existing user untouched when seeding in production', function () {
    configureInitialSuperAdminServer();
    config(['super_admin.password' => 'V3ry-Str0ng-Passphrase-2026']);

    $existing = User::factory()->create([
        'email' => 'superadmin@example.com',
        'first_name' => 'Existing',
        'last_name' => 'Operator',
        'password' => 'original-secret-password',
        'is_active' => false,
        'email_verified_at' => null,
    ]);
    $existing->assignRole(RoleEnum::Admin->value);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->assertSuccessful();

    $existing->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($existing->first_name)->toBe('Existing')
        ->and($existing->last_name)->toBe('Operator')
        ->and($existing->is_active)->toBeFalse()
        ->and($existing->email_verified_at)->toBeNull()
        ->and(Hash::check('original-secret-password', $existing->password))->toBeTrue()
        ->and($existing->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and($existing->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();
});

it('leaves the instance alone when a soft-deleted user held the seeded email', function () {
    configureInitialSuperAdminServer();
    config(['super_admin.password' => 'V3ry-Str0ng-Passphrase-2026']);
    $deleted = User::factory()->create(['email' => 'superadmin@example.com']);
    app(DeleteUserAction::class)->execute($deleted);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and(User::withTrashed()->findOrFail($deleted->id)->email)->not->toBe('superadmin@example.com');
});

it('creates the seeded super admin locally with the example password', function () {
    config(['super_admin.password' => 'password']);

    $this->seed(CreateSuperAdminSeeder::class);

    $user = User::query()->sole();

    expect(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();
});

it('leaves an existing user untouched when re-seeding locally', function () {
    $existing = User::factory()->create([
        'email' => 'superadmin@example.com',
        'first_name' => 'Existing',
        'is_active' => false,
        'email_verified_at' => null,
    ]);

    $this->seed(CreateSuperAdminSeeder::class);
    $existing->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($existing->first_name)->toBe('Existing')
        ->and($existing->is_active)->toBeFalse()
        ->and($existing->email_verified_at)->toBeNull()
        ->and($existing->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();
});

it('ships .env.example with the local default password', function () {
    expect(file_get_contents(base_path('.env.example')))->toMatch('/^INITIAL_SUPER_ADMIN_PASSWORD=password$/m');
});
