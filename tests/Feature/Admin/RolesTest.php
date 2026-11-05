<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\SuperAdminGuardService;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function userWithRole(RoleEnum|string $role): User
{
    return User::factory()->create()->assignRole($role instanceof RoleEnum ? $role->value : $role);
}

it('gives a fresh migrate --seed a super-admin who can log in', function () {
    $this->seed();
    $this->seed();

    $this->post(route('login.store'), [
        'email' => config('super_admin.email'),
        'password' => config('super_admin.password'),
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->sole();

    expect($user->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

it('maps the built-in roles to their default permissions', function (RoleEnum $role, array $allowed) {
    $user = userWithRole($role);

    foreach (PermissionEnum::cases() as $permission) {
        expect($user->can($permission->value))->toBe(in_array($permission, $allowed, true), $permission->value);
    }
})->with([
    'super-admin' => [RoleEnum::SuperAdmin, PermissionEnum::cases()],
    'admin' => [RoleEnum::Admin, [
        PermissionEnum::ProjectsView,
        PermissionEnum::ProjectsManage,
        PermissionEnum::ChecksRun,
        PermissionEnum::UsersManage,
        PermissionEnum::GroupsManage,
        PermissionEnum::ActivityView,
        PermissionEnum::AiUse,
        PermissionEnum::ExceptionsManage,
    ]],
    'reader' => [RoleEnum::Reader, [PermissionEnum::ProjectsView]],
]);

it('lets the super-admin pass a permission that no role holds yet', function () {
    $user = userWithRole(RoleEnum::SuperAdmin);
    Role::findByName(RoleEnum::SuperAdmin->value)->syncPermissions([]);

    expect($user->fresh()->can(PermissionEnum::SettingsManage->value))->toBeTrue();
});

it('shares the user permissions with Inertia', function () {
    $this->actingAs(userWithRole(RoleEnum::Reader))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', [PermissionEnum::ProjectsView->value]));
});

it('serves the role pages under settings', function () {
    expect(route('roles.index', absolute: false))->toBe('/settings/roles')
        ->and(route('roles.create', absolute: false))->toBe('/settings/roles/create');
});

it('keeps role pages behind roles.manage', function (RoleEnum $role) {
    $user = userWithRole($role);
    $custom = Role::create(['name' => 'auditor']);

    $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
    $this->actingAs($user)->get(route('roles.create'))->assertForbidden();
    $this->actingAs($user)->post(route('roles.store'), ['name' => 'x', 'permissions' => []])->assertForbidden();
    $this->actingAs($user)->put(route('roles.update', $custom), ['name' => 'x', 'permissions' => []])->assertForbidden();
    $this->actingAs($user)->delete(route('roles.destroy', $custom))->assertForbidden();
})->with([RoleEnum::Admin, RoleEnum::Reader]);

it('creates, updates and deletes a custom role', function () {
    $this->actingAs(userWithRole(RoleEnum::SuperAdmin));

    $this->get(route('roles.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/roles/index')->has('roles.data', 3));

    $this->post(route('roles.store'), [
        'name' => 'operator',
        'permissions' => [PermissionEnum::ProjectsView->value, PermissionEnum::ChecksRun->value],
    ])->assertRedirect(route('roles.index'));

    $role = Role::findByName('operator');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['checks.run', 'projects.view']);

    $member = userWithRole('operator');
    expect($member->can(PermissionEnum::ChecksRun->value))->toBeTrue()
        ->and($member->can(PermissionEnum::ProjectsManage->value))->toBeFalse();

    $this->put(route('roles.update', $role), [
        'name' => 'runner',
        'permissions' => [PermissionEnum::ProjectsView->value],
    ])->assertRedirect(route('roles.index'));

    expect($role->fresh()->name)->toBe('runner')
        ->and($member->fresh()->can(PermissionEnum::ChecksRun->value))->toBeFalse();

    $this->delete(route('roles.destroy', $role))->assertRedirect(route('roles.index'));

    expect(Role::query()->where('name', 'runner')->exists())->toBeFalse();
});

it('validates custom roles', function () {
    $this->actingAs(userWithRole(RoleEnum::SuperAdmin));
    Role::create(['name' => 'operator']);

    $this->post(route('roles.store'), ['name' => 'operator', 'permissions' => []])->assertSessionHasErrors('name');
    $this->post(route('roles.store'), ['name' => 'admin', 'permissions' => []])->assertSessionHasErrors('name');
    $this->post(route('roles.store'), ['name' => 'other', 'permissions' => ['projects.delete']])->assertSessionHasErrors('permissions.0');
});

it('keeps built-in roles read-only, even for the super-admin', function (RoleEnum $builtIn) {
    $this->actingAs(userWithRole(RoleEnum::SuperAdmin));
    $role = Role::findByName($builtIn->value);

    $this->get(route('roles.edit', $role))
        ->assertInertia(fn (Assert $page) => $page->component('admin/roles/form')->where('role.can_edit', false)->where('role.is_built_in', true));
    $this->put(route('roles.update', $role), ['name' => 'renamed', 'permissions' => []])->assertForbidden();
    $this->delete(route('roles.destroy', $role))->assertForbidden();

    expect(Role::query()->where('name', $builtIn->value)->exists())->toBeTrue();
})->with(RoleEnum::cases());

it('detects the last active super-admin', function () {
    $guard = app(SuperAdminGuardService::class);
    $first = userWithRole(RoleEnum::SuperAdmin);
    $admin = userWithRole(RoleEnum::Admin);

    expect($guard->isLastSuperAdmin($first))->toBeTrue()
        ->and($guard->isLastSuperAdmin($admin))->toBeFalse();

    $second = userWithRole(RoleEnum::SuperAdmin);

    expect($guard->isLastSuperAdmin($first))->toBeFalse();

    $second->forceFill(['is_active' => false])->save();

    expect($guard->isLastSuperAdmin($first))->toBeTrue()
        ->and($guard->isLastSuperAdmin($second))->toBeFalse();
});

it('makes the first CLI user super-admin and later ones reader', function () {
    $this->artisan('excubia:create-user', ['--first-name' => 'Ada', '--last-name' => 'Lovelace', '--email' => 'ada@example.com'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Role', 'super-admin', ['admin', 'reader', 'super-admin'])
        ->assertSuccessful();

    $this->artisan('excubia:create-user', ['--first-name' => 'Bob', '--last-name' => 'Builder', '--email' => 'bob@example.com', '--role' => 'reader'])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ada@example.com')->sole()->getRoleNames()->all())->toBe(['super-admin'])
        ->and(User::query()->where('email', 'bob@example.com')->sole()->getRoleNames()->all())->toBe(['reader']);
});
