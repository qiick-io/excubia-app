<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/**
 * An admin who can manage users; unique helper name so it cannot clash with the other test files.
 */
function usernameManagingAdmin(): User
{
    return User::factory()->create()->assignRole(RoleEnum::Admin->value);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usernameUserPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        'roles' => [RoleEnum::Reader->value],
        'is_active' => true,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usernameProfilePayload(User $user, array $overrides = []): array
{
    return [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
        ...$overrides,
    ];
}

it('canonicalizes usernames on the user model', function () {
    $user = User::factory()->make(['username' => '  LuCa.Hopper  ']);

    expect($user->username)->toBe('luca.hopper');

    $user->username = '   ';

    expect($user->username)->toBeNull();

    $user->username = ' Ada_99 ';

    expect($user->username)->toBe('ada_99');
});

it('creates a user with a normalized username', function () {
    $this->actingAs(usernameManagingAdmin())
        ->post(route('users.store'), usernameUserPayload(['username' => '  Grace.Hopper  ']))
        ->assertRedirect(route('users.index'));

    expect(User::query()->where('email', 'grace@example.com')->sole()->username)->toBe('grace.hopper');
});

it('creates a user without a username', function () {
    $this->actingAs(usernameManagingAdmin())
        ->post(route('users.store'), usernameUserPayload())
        ->assertRedirect(route('users.index'));

    expect(User::query()->where('email', 'grace@example.com')->sole()->username)->toBeNull();
});

it('updates and clears a username in the admin form', function () {
    $target = User::factory()->create(['username' => 'grace']);
    $admin = usernameManagingAdmin();

    $this->actingAs($admin)
        ->put(route('users.update', $target), usernameUserPayload([
            'email' => $target->email,
            'password' => '',
            'username' => '  NEW.Name  ',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->username)->toBe('new.name');

    $this->actingAs($admin)
        ->put(route('users.update', $target), usernameUserPayload([
            'email' => $target->email,
            'password' => '',
            'username' => '',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->username)->toBeNull();
});

it('rejects a username already used with a different case', function () {
    User::factory()->create(['username' => 'luca']);

    $this->actingAs(usernameManagingAdmin())
        ->post(route('users.store'), usernameUserPayload([
            'email' => 'other@example.com',
            'username' => 'Luca',
        ]))
        ->assertSessionHasErrors('username');

    expect(User::query()->where('username', 'luca')->count())->toBe(1)
        ->and(User::query()->where('email', 'other@example.com')->exists())->toBeFalse();
});

it('rejects usernames with invalid characters or length', function (string $username) {
    $this->actingAs(usernameManagingAdmin())
        ->post(route('users.store'), usernameUserPayload(['username' => $username]))
        ->assertSessionHasErrors('username');

    expect(User::query()->where('email', 'grace@example.com')->exists())->toBeFalse();
})->with([
    'ab',
    'bad name',
    'bad@name',
    '-leading',
    'trailing-',
    'way-too-long-username-that-never-fits',
]);

it('accepts dotted, dashed and underscored usernames', function () {
    $this->actingAs(usernameManagingAdmin())
        ->post(route('users.store'), usernameUserPayload(['username' => 'a.b-c_d']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.index'));

    expect(User::query()->where('email', 'grace@example.com')->sole()->username)->toBe('a.b-c_d');
});

it('updates the profile username and keeps it on repeated saves', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $this->actingAs($user)
        ->patch(route('profile.update'), usernameProfilePayload($user, ['username' => '  ADA_99 ']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->username)->toBe('ada_99');

    $this->actingAs($user)
        ->patch(route('profile.update'), usernameProfilePayload($user, ['username' => 'ada_99']))
        ->assertSessionHasNoErrors();
});

it('rejects another user username on profile update', function () {
    $user = User::factory()->create();
    User::factory()->create(['username' => 'luca']);

    $this->actingAs($user)
        ->patch(route('profile.update'), usernameProfilePayload($user, ['username' => 'Luca']))
        ->assertSessionHasErrors('username');

    expect($user->fresh()->username)->toBeNull();
});

it('clears the profile username when left empty', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $this->actingAs($user)
        ->patch(route('profile.update'), usernameProfilePayload($user, ['username' => '']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->username)->toBeNull();
});

it('rejects invalid profile usernames', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), usernameProfilePayload($user, ['username' => 'bad name']))
        ->assertSessionHasErrors('username');

    expect($user->fresh()->username)->toBeNull();
});
