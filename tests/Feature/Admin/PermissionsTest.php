<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Read-only permissions page (`/settings/permissions`) with the enum re-sync, behind `roles.manage`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function permissionsPageUser(RoleEnum|string $role): User
{
    return User::factory()->create()->assignRole($role instanceof RoleEnum ? $role->value : $role);
}

it('serves the permissions page under settings', function () {
    expect(route('permissions.index', absolute: false))->toBe('/settings/permissions')
        ->and(route('permissions.sync', absolute: false))->toBe('/settings/permissions/sync');
});

it('keeps the permissions page behind roles.manage', function (RoleEnum $role) {
    $user = permissionsPageUser($role);

    $this->actingAs($user)->get(route('permissions.index'))->assertForbidden();
    $this->actingAs($user)->post(route('permissions.sync'))->assertForbidden();
})->with([RoleEnum::Admin, RoleEnum::Reader]);

it('sends guests to the login page', function () {
    $this->get(route('permissions.index'))->assertRedirect(route('login'));
});

it('lets a custom role with roles.manage open the permissions page', function () {
    Role::create(['name' => 'access-manager'])->givePermissionTo(PermissionEnum::RolesManage->value);

    $this->actingAs(permissionsPageUser('access-manager'))
        ->get(route('permissions.index'))
        ->assertOk();
});

it('lists every permission with the roles that hold it', function () {
    $this->actingAs(permissionsPageUser(RoleEnum::SuperAdmin))
        ->get(route('permissions.index', ['per_page' => 100]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/permissions/index')
            ->has('permissions.data', count(PermissionEnum::cases()))
            ->where('permissions.data', fn ($rows): bool => collect($rows)->pluck('name')->sort()->values()->all()
                === collect(PermissionEnum::values())->sort()->values()->all())
            ->where('permissions.data', fn ($rows): bool => collect($rows)->firstWhere('name', PermissionEnum::ProjectsView->value)['roles']
                === [RoleEnum::Admin->value, RoleEnum::Reader->value, RoleEnum::SuperAdmin->value])
            ->where('permissions.data', fn ($rows): bool => collect($rows)->firstWhere('name', PermissionEnum::JobsManage->value)['roles']
                === [RoleEnum::SuperAdmin->value]));
});

it('syncs the permissions from the enum idempotently', function () {
    Permission::findByName(PermissionEnum::ActivityView->value)->delete();
    Role::findByName(RoleEnum::Reader->value)->givePermissionTo(PermissionEnum::UsersManage->value);
    $custom = Role::create(['name' => 'auditor']);
    $custom->givePermissionTo(PermissionEnum::ProjectsView->value);

    $manager = permissionsPageUser(RoleEnum::SuperAdmin);

    foreach ([1, 2] as $run) {
        $this->actingAs($manager)
            ->post(route('permissions.sync'))
            ->assertRedirect(route('permissions.index'))
            ->assertInertiaFlash('toast.type', 'success');

        expect(Permission::query()->pluck('name')->sort()->values()->all())
            ->toBe(collect(PermissionEnum::values())->sort()->values()->all())
            ->and(Role::findByName(RoleEnum::Reader->value)->permissions->pluck('name')->all())
            ->toBe([PermissionEnum::ProjectsView->value])
            ->and($custom->fresh()->permissions->pluck('name')->all())
            ->toBe([PermissionEnum::ProjectsView->value]);
    }
});
