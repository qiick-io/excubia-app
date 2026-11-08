<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
 * #151: you can only grant what you hold. These cover the user, group and role endpoints for
 * non-admins holding `can-edit-users`, `can-edit-groups` and `can-edit-roles`.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/**
 * A user whose only role is a custom one with these permissions.
 *
 * @param  list<PermissionEnum>  $permissions
 */
function escalationActor(string $roleName, array $permissions): User
{
    Role::create(['name' => $roleName])->givePermissionTo(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions));

    return User::factory()->create()->assignRole($roleName);
}

function userManager(): User
{
    return escalationActor('user-manager', [PermissionEnum::CanShowUsers, PermissionEnum::CanCreateUsers, PermissionEnum::CanEditUsers, PermissionEnum::CanDeleteUsers, PermissionEnum::CanShowProjects]);
}

function groupManager(): User
{
    return escalationActor('group-manager', [PermissionEnum::CanShowGroups, PermissionEnum::CanCreateGroups, PermissionEnum::CanEditGroups, PermissionEnum::CanDeleteGroups, PermissionEnum::CanShowProjects]);
}

function roleManager(): User
{
    return escalationActor('role-manager', [PermissionEnum::CanShowRoles, PermissionEnum::CanCreateRoles, PermissionEnum::CanEditRoles, PermissionEnum::CanDeleteRoles, PermissionEnum::CanShowProjects]);
}

/**
 * @param  list<string>  $roles
 * @return array<string, mixed>
 */
function escalationUserPayload(User $user, array $roles): array
{
    return ['first_name' => 'Same', 'last_name' => null, 'email' => $user->email, 'password' => '', 'is_active' => true, 'roles' => $roles];
}

it('stops a can-edit-users holder from giving themselves the admin role', function () {
    $manager = userManager();

    $this->actingAs($manager)
        ->put(route('users.update', $manager), escalationUserPayload($manager, ['user-manager', RoleEnum::Admin->value]))
        ->assertSessionHasErrors(['roles', 'roles.1']);

    expect($manager->fresh()->hasRole(RoleEnum::Admin->value))->toBeFalse();
});

it('stops anyone but a super-admin from changing their own roles, even to a role they hold', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->put(route('users.update', $admin), escalationUserPayload($admin, [RoleEnum::Admin->value, RoleEnum::Reader->value]))
        ->assertSessionHasErrors('roles');
    $this->actingAs($admin)
        ->put(route('users.update', $admin), escalationUserPayload($admin, [RoleEnum::Admin->value]))
        ->assertSessionHasNoErrors();

    $superAdmin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);

    $this->actingAs($superAdmin)
        ->put(route('users.update', $superAdmin), escalationUserPayload($superAdmin, [RoleEnum::SuperAdmin->value, RoleEnum::Reader->value]))
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->getRoleNames()->all())->toBe([RoleEnum::Admin->value])
        ->and($superAdmin->fresh()->hasRole(RoleEnum::Reader->value))->toBeTrue();
});

it('lets a can-edit-users holder grant only roles whose permissions they hold', function () {
    $manager = userManager();
    Role::create(['name' => 'viewer'])->givePermissionTo(PermissionEnum::CanShowProjects->value);
    Role::create(['name' => 'operator'])->givePermissionTo([PermissionEnum::CanShowProjects->value, PermissionEnum::CanRunChecks->value]);
    $target = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    $this->actingAs($manager)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === [RoleEnum::Reader->value, 'user-manager', 'viewer']));

    $this->actingAs($manager)
        ->put(route('users.update', $target), escalationUserPayload($target, ['viewer']))
        ->assertSessionHasNoErrors();

    foreach (['operator', RoleEnum::Admin->value, RoleEnum::SuperAdmin->value] as $role) {
        $this->actingAs($manager)
            ->put(route('users.update', $target), escalationUserPayload($target, [$role]))
            ->assertSessionHasErrors('roles.0');
        $this->actingAs($manager)
            ->post(route('users.store'), [...escalationUserPayload($target, [$role]), 'email' => "new-{$role}@example.com", 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('roles.0');
    }

    expect($target->fresh()->getRoleNames()->all())->toBe(['viewer'])
        ->and(User::query()->where('email', 'like', 'new-%')->exists())->toBeFalse();
});

it('keeps users above the actor out of reach, so nobody strips or takes over a stronger account', function () {
    $manager = userManager();
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $groupAdmin = User::factory()->create();
    UserGroup::query()->create(['name' => 'admins'])->roles()->sync([Role::findByName(RoleEnum::Admin->value)->id]);
    UserGroup::query()->where('name', 'admins')->sole()->users()->sync([$groupAdmin->id]);

    foreach ([$admin, $groupAdmin] as $target) {
        $this->actingAs($manager)
            ->put(route('users.update', $target), [...escalationUserPayload($target, [RoleEnum::Reader->value]), 'password' => 'taken-over-password'])
            ->assertForbidden();
        $this->actingAs($manager)->post(route('users.password-reset', $target))->assertForbidden();
        $this->actingAs($manager)->delete(route('users.destroy', $target))->assertForbidden();
    }

    $this->actingAs($manager)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->firstWhere('id', $admin->id)['can_update'] === false
                && collect($rows)->firstWhere('id', $manager->id)['can_update'] === true
                && collect($rows)->firstWhere('id', $manager->id)['can_change_roles'] === false));

    expect($admin->fresh()->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

it('stops a can-edit-groups holder from escalating through a group', function () {
    $manager = groupManager();
    $other = User::factory()->create();
    $admins = UserGroup::query()->create(['name' => 'admins']);
    $admins->roles()->sync([Role::findByName(RoleEnum::Admin->value)->id]);

    // A role they do not hold, for themselves or anybody else.
    foreach ([[$manager->id], [$other->id]] as $users) {
        $this->actingAs($manager)
            ->post(route('groups.store'), ['name' => 'escalate', 'roles' => [RoleEnum::Admin->value], 'users' => $users])
            ->assertSessionHasErrors('roles.0');
    }

    // A group carrying a role they do not hold is out of reach.
    $this->actingAs($manager)
        ->put(route('groups.update', $admins), ['name' => 'admins', 'roles' => [RoleEnum::Admin->value], 'users' => [$manager->id]])
        ->assertForbidden();
    $this->actingAs($manager)->delete(route('groups.destroy', $admins))->assertForbidden();

    // Even a role they hold: nobody joins a group that carries roles.
    $this->actingAs($manager)
        ->post(route('groups.store'), ['name' => 'viewers', 'roles' => [RoleEnum::Reader->value], 'users' => [$manager->id]])
        ->assertSessionHasErrors('users');

    expect($manager->fresh()->can(PermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and(UserGroup::query()->whereIn('name', ['escalate', 'viewers'])->exists())->toBeFalse()
        ->and($admins->users()->count())->toBe(0);
});

it('lets a can-edit-groups holder grant roles they hold to others, but not change their own group', function () {
    $manager = groupManager();
    $other = User::factory()->create();

    $this->actingAs($manager)
        ->post(route('groups.store'), ['name' => 'viewers', 'roles' => [RoleEnum::Reader->value], 'users' => [$other->id]])
        ->assertSessionHasNoErrors();
    $this->actingAs($manager)
        ->post(route('groups.store'), ['name' => 'team', 'roles' => [], 'users' => [$manager->id]])
        ->assertSessionHasNoErrors();

    $team = UserGroup::query()->where('name', 'team')->sole();

    $this->actingAs($manager)
        ->put(route('groups.update', $team), ['name' => 'team', 'roles' => [RoleEnum::Reader->value], 'users' => [$manager->id]])
        ->assertSessionHasErrors('roles');

    $viewers = UserGroup::query()->where('name', 'viewers')->sole();

    $this->actingAs($manager)
        ->put(route('groups.update', $viewers), ['name' => 'viewers', 'roles' => [RoleEnum::Reader->value], 'users' => [$other->id, $manager->id]])
        ->assertSessionHasErrors('users');

    $this->actingAs($manager)->get(route('groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data', fn ($rows) => collect($rows)->firstWhere('name', 'team')['can_change_roles'] === false
                && collect($rows)->firstWhere('name', 'viewers')['can_change_roles'] === true)
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === ['group-manager', RoleEnum::Reader->value]));

    expect($other->fresh()->can(PermissionEnum::CanShowProjects->value))->toBeTrue()
        ->and($team->roles()->count())->toBe(0)
        ->and($viewers->users()->whereKey($manager->id)->exists())->toBeFalse();
});

it('lets a group member who is super-admin change their own group', function () {
    $superAdmin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $team = UserGroup::query()->create(['name' => 'team']);
    $team->users()->sync([$superAdmin->id]);

    $this->actingAs($superAdmin)
        ->put(route('groups.update', $team), ['name' => 'team', 'roles' => [RoleEnum::Admin->value], 'users' => [$superAdmin->id]])
        ->assertSessionHasNoErrors();

    expect($team->roles()->pluck('name')->all())->toBe([RoleEnum::Admin->value]);
});

it('stops a can-edit-roles holder from putting permissions they lack into a role', function () {
    $manager = roleManager();
    $reachable = Role::create(['name' => 'viewer'])->givePermissionTo(PermissionEnum::CanShowProjects->value);
    $stronger = Role::create(['name' => 'operator'])->givePermissionTo([PermissionEnum::CanShowProjects->value, PermissionEnum::CanRunChecks->value]);

    $this->actingAs($manager)
        ->post(route('roles.store'), ['name' => 'sneaky', 'permissions' => [PermissionEnum::CanShowProjects->value, PermissionEnum::CanEditUsers->value]])
        ->assertSessionHasErrors('permissions.1');
    $this->actingAs($manager)
        ->put(route('roles.update', $reachable), ['name' => 'viewer', 'permissions' => [PermissionEnum::CanRunChecks->value]])
        ->assertSessionHasErrors('permissions.0');
    // Their own role: still only what they hold.
    $this->actingAs($manager)
        ->put(route('roles.update', Role::findByName('role-manager')), ['name' => 'role-manager', 'permissions' => [PermissionEnum::CanShowRoles->value, PermissionEnum::CanEditRoles->value, PermissionEnum::CanEditUsers->value]])
        ->assertSessionHasErrors('permissions.2');

    // A role holding a permission they lack is out of reach (no stripping).
    $this->actingAs($manager)
        ->put(route('roles.update', $stronger), ['name' => 'operator', 'permissions' => [PermissionEnum::CanShowProjects->value]])
        ->assertForbidden();
    $this->actingAs($manager)->delete(route('roles.destroy', $stronger))->assertForbidden();
    $this->actingAs($manager)->get(route('roles.edit', $stronger))
        ->assertInertia(fn (Assert $page) => $page->where('role.can_edit', false));

    $this->actingAs($manager)
        ->put(route('roles.update', $reachable), ['name' => 'viewer', 'permissions' => [PermissionEnum::CanShowProjects->value, PermissionEnum::CanShowRoles->value]])
        ->assertSessionHasNoErrors();

    expect(Role::query()->where('name', 'sneaky')->exists())->toBeFalse()
        ->and($stronger->fresh()->permissions()->count())->toBe(2)
        ->and($manager->fresh()->can(PermissionEnum::CanEditUsers->value))->toBeFalse()
        ->and($reachable->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe([PermissionEnum::CanShowProjects->value, PermissionEnum::CanShowRoles->value]);
});
