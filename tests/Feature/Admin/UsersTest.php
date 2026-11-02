<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function managedUser(RoleEnum $role = RoleEnum::Reader, array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole($role->value);
}

/**
 * @return array<string, mixed>
 */
function userPayload(array $overrides = []): array
{
    return [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        'roles' => [RoleEnum::Reader->value],
        'is_active' => true,
        ...$overrides,
    ];
}

it('keeps user pages away from a reader', function () {
    $reader = managedUser();
    $other = managedUser();

    $this->actingAs($reader)->get(route('users.index'))->assertForbidden();
    $this->actingAs($reader)->get(route('users.create'))->assertForbidden();
    $this->actingAs($reader)->post(route('users.store'), userPayload())->assertForbidden();
    $this->actingAs($reader)->get(route('users.edit', $other))->assertForbidden();
    $this->actingAs($reader)->put(route('users.update', $other), userPayload())->assertForbidden();
    $this->actingAs($reader)->delete(route('users.destroy', $other))->assertForbidden();
    $this->actingAs($reader)->post(route('users.password-reset', $other))->assertForbidden();
});

it('lists users for an admin', function () {
    $admin = managedUser(RoleEnum::Admin);
    managedUser();

    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page->component('users/index')->has('users', 2));
});

it('creates a verified user with roles', function () {
    $this->actingAs(managedUser(RoleEnum::Admin))
        ->post(route('users.store'), userPayload())
        ->assertRedirect(route('users.index'));

    $user = User::query()->where('email', 'grace@example.com')->sole();

    expect($user->hasRole(RoleEnum::Reader->value))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_active)->toBeTrue();
});

it('validates new users', function () {
    $admin = managedUser(RoleEnum::Admin);

    $this->actingAs($admin)
        ->post(route('users.store'), userPayload(['email' => $admin->email, 'roles' => []]))
        ->assertSessionHasErrors(['email', 'roles']);
});

it('updates a user and keeps the password when empty', function () {
    $user = managedUser();
    $password = $user->password;

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->put(route('users.update', $user), userPayload(['password' => '', 'roles' => [RoleEnum::Admin->value]]))
        ->assertRedirect(route('users.index'));

    $user->refresh();

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->password)->toBe($password)
        ->and($user->getRoleNames()->all())->toBe([RoleEnum::Admin->value]);
});

it('deactivates a user, who can then not log in', function () {
    $user = managedUser();

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->put(route('users.update', $user), userPayload(['email' => $user->email, 'password' => '', 'is_active' => false]))
        ->assertRedirect(route('users.index'));

    expect($user->fresh()->is_active)->toBeFalse();

    auth()->logout();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('logs out a user who was deactivated while signed in', function () {
    $user = managedUser();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('deletes a user', function () {
    $user = managedUser();

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->delete(route('users.destroy', $user))
        ->assertRedirect(route('users.index'));

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('blocks deleting or deactivating yourself', function () {
    $admin = managedUser(RoleEnum::Admin);

    $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
    $this->actingAs($admin)
        ->put(route('users.update', $admin), userPayload(['email' => $admin->email, 'roles' => [RoleEnum::Admin->value], 'is_active' => false]))
        ->assertSessionHasErrors('is_active');

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('protects the last super-admin', function () {
    $superAdmin = managedUser(RoleEnum::SuperAdmin);
    managedUser(RoleEnum::SuperAdmin, ['is_active' => false]);

    // The inactive super-admin does not count: $superAdmin is the last one.
    $this->actingAs($superAdmin)
        ->put(route('users.update', $superAdmin), userPayload(['email' => $superAdmin->email, 'roles' => [RoleEnum::Admin->value]]))
        ->assertSessionHasErrors('roles');

    expect($superAdmin->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();

    $second = managedUser(RoleEnum::SuperAdmin);

    $this->actingAs($second)
        ->put(route('users.update', $superAdmin), userPayload(['email' => $superAdmin->email, 'roles' => [RoleEnum::Admin->value]]))
        ->assertSessionHasNoErrors();

    expect($superAdmin->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();

    $this->actingAs($second)->delete(route('users.destroy', $second))->assertForbidden();
});

it('lets only a super-admin manage super-admins', function () {
    $admin = managedUser(RoleEnum::Admin);
    $superAdmin = managedUser(RoleEnum::SuperAdmin);
    $reader = managedUser();

    $this->actingAs($admin)->get(route('users.edit', $superAdmin))->assertForbidden();
    $this->actingAs($admin)->delete(route('users.destroy', $superAdmin))->assertForbidden();
    $this->actingAs($admin)
        ->put(route('users.update', $reader), userPayload(['email' => $reader->email, 'roles' => [RoleEnum::SuperAdmin->value]]))
        ->assertSessionHasErrors('roles.0');
    $this->actingAs($admin)
        ->put(route('users.update', $admin), userPayload(['email' => $admin->email, 'roles' => [RoleEnum::SuperAdmin->value]]))
        ->assertSessionHasErrors('roles.0');

    expect($reader->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();
});

it('sends a password-reset email through Fortify', function () {
    Notification::fake();
    $user = managedUser();

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->post(route('users.password-reset', $user))
        ->assertRedirect();

    Notification::assertSentTo($user, ResetPassword::class);
});
