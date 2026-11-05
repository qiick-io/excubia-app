<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function groupMember(?RoleEnum $role = null, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    return $role === null ? $user : $user->assignRole($role->value);
}

function groupWithRoles(string $name, array $roles, array $users = []): UserGroup
{
    $group = UserGroup::query()->create(['name' => $name]);
    $group->roles()->sync(Role::query()->whereIn('name', $roles)->pluck('id'));
    $group->users()->sync(collect($users)->pluck('id'));

    return $group;
}

it('keeps group pages behind groups.manage', function () {
    $reader = groupMember(RoleEnum::Reader);
    $group = groupWithRoles('ops', []);

    $this->actingAs($reader)->get(route('groups.index'))->assertForbidden();
    $this->actingAs($reader)->post(route('groups.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($reader)->put(route('groups.update', $group), ['name' => 'x'])->assertForbidden();
    $this->actingAs($reader)->delete(route('groups.destroy', $group))->assertForbidden();
});

it('lets a user gain and lose a permission through a group', function () {
    $admin = groupMember(RoleEnum::Admin);
    $member = groupMember();

    expect($member->can(PermissionEnum::CanShowProjects->value))->toBeFalse();

    $this->actingAs($admin)->post(route('groups.store'), [
        'name' => 'viewers',
        'roles' => [RoleEnum::Reader->value],
        'users' => [$member->id],
    ])->assertRedirect(route('groups.index'));

    $group = UserGroup::query()->where('name', 'viewers')->sole();

    expect($member->fresh()->can(PermissionEnum::CanShowProjects->value))->toBeTrue();

    $this->actingAs($member)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', [PermissionEnum::CanShowProjects->value]));

    $this->actingAs($admin)->put(route('groups.update', $group), [
        'name' => 'viewers',
        'roles' => [RoleEnum::Reader->value],
        'users' => [],
    ])->assertRedirect(route('groups.index'));

    expect($member->fresh()->can(PermissionEnum::CanShowProjects->value))->toBeFalse();
});

it('makes group members super-admins through a group role', function () {
    $member = groupMember();
    groupWithRoles('owners', [RoleEnum::SuperAdmin->value], [$member]);

    expect($member->can(PermissionEnum::CanManageProjectSettings->value))->toBeTrue();
});

it('lists and deletes groups', function () {
    $admin = groupMember(RoleEnum::Admin);
    $member = groupMember();
    $group = groupWithRoles('viewers', [RoleEnum::Reader->value], [$member]);

    $this->actingAs($admin)->get(route('groups.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/groups/index')
            ->has('groups.data', 1)
            ->where('groups.data.0.users_count', 1)
            ->where('groups.data.0.users.0.id', $member->id));

    $this->actingAs($admin)->delete(route('groups.destroy', $group))->assertRedirect(route('groups.index'));

    expect(UserGroup::query()->count())->toBe(0)
        ->and($member->fresh()->can(PermissionEnum::CanShowProjects->value))->toBeFalse();
});

it('never removes the last super-admin through a group', function () {
    $owner = groupMember();
    $group = groupWithRoles('owners', [RoleEnum::SuperAdmin->value], [$owner]);

    $this->actingAs($owner)->delete(route('groups.destroy', $group))->assertSessionHasErrors('group');
    $this->actingAs($owner)->put(route('groups.update', $group), ['name' => 'owners', 'roles' => [], 'users' => [$owner->id]])
        ->assertSessionHasErrors('roles');
    $this->actingAs($owner)->put(route('groups.update', $group), ['name' => 'owners', 'roles' => [RoleEnum::SuperAdmin->value], 'users' => []])
        ->assertSessionHasErrors('roles');

    expect($group->fresh()->users()->count())->toBe(1)
        ->and($owner->fresh()->can(PermissionEnum::CanManageProjectSettings->value))->toBeTrue();

    groupMember(RoleEnum::SuperAdmin);

    $this->actingAs($owner)->delete(route('groups.destroy', $group))->assertSessionHasNoErrors();

    expect(UserGroup::query()->count())->toBe(0);
});

it('protects a super-admin who is one only through a group', function () {
    $owner = groupMember();
    groupWithRoles('owners', [RoleEnum::SuperAdmin->value], [$owner]);

    $this->actingAs($owner)->delete(route('users.destroy', $owner))->assertForbidden();
    expect(app(SuperAdminGuardService::class)->isLastSuperAdmin($owner))->toBeTrue();
});

it('keeps super-admin groups away from admins', function () {
    $admin = groupMember(RoleEnum::Admin);
    $owners = groupWithRoles('owners', [RoleEnum::SuperAdmin->value], [groupMember()]);

    $this->actingAs($admin)->get(route('groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.0.can_update', false)
            ->where('roles', fn ($roles) => ! in_array(RoleEnum::SuperAdmin->value, collect($roles)->pluck('name')->all(), true)));
    $this->actingAs($admin)->put(route('groups.update', $owners), ['name' => 'owners', 'roles' => [], 'users' => [$admin->id]])->assertForbidden();
    $this->actingAs($admin)->delete(route('groups.destroy', $owners))->assertForbidden();
    $this->actingAs($admin)->post(route('groups.store'), [
        'name' => 'escalate',
        'roles' => [RoleEnum::SuperAdmin->value],
        'users' => [$admin->id],
    ])->assertSessionHasErrors('roles.0');

    expect($admin->fresh()->can(PermissionEnum::CanManageProjectSettings->value))->toBeFalse();
});

it('searches and sorts groups', function () {
    $admin = groupMember(RoleEnum::Admin);
    groupWithRoles('viewers', []);
    groupWithRoles('operators', []);
    UserGroup::query()->create(['name' => 'auditors', 'description' => 'Read-only operators']);

    $this->actingAs($admin)->get(route('groups.index', ['search' => 'operators', 'sort' => 'name', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups.data', 2)
            ->where('groups.data.0.name', 'operators')
            ->where('groups.data.1.name', 'auditors')
            ->where('filters', ['search' => 'operators', 'sort' => 'name', 'direction' => 'desc']));
});
