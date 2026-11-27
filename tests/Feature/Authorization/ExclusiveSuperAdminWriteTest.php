<?php

declare(strict_types=1);

use App\Actions\CreateUserAction;
use App\Actions\DeleteUserGroupAction;
use App\Actions\SaveUserGroupAction;
use App\Actions\UpdateUserAction;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Qiick\Access\ExclusiveSuperAdminException;
use Spatie\Permission\Models\Role;

/*
 * Every role and group write goes through qiick/laravel-access ExclusiveSuperAdmin::write()
 * (0.1.0-beta.3): super-admin stands alone, and an actor who may not manage super-admins never
 * makes one. A refused write rolls back whole.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
});

it('refuses an admin who creates a super-admin and leaves no user behind', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

    expect(fn () => app(CreateUserAction::class)->execute($admin, 'New', null, 'new@example.test', null, 'secret-password-123', [RoleEnum::SuperAdmin->value]))
        ->toThrow(ExclusiveSuperAdminException::class);

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});

it('refuses an admin who promotes a user and keeps the user unchanged', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $target = User::factory()->create(['first_name' => 'Before'])->assignRole(RoleEnum::Reader->value);

    expect(fn () => app(UpdateUserAction::class)->execute($admin, $target, 'After', $target->last_name, $target->email, $target->username, null, [RoleEnum::SuperAdmin->value], true))
        ->toThrow(ExclusiveSuperAdminException::class);

    $target->refresh();
    expect($target->first_name)->toBe('Before')
        ->and($target->getRoleNames()->all())->toBe([RoleEnum::Reader->value]);
});

it('refuses a group that gives a member super-admin next to a direct role', function () {
    $superAdmin = User::query()->sole();
    $member = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    expect(fn () => app(SaveUserGroupAction::class)->execute($superAdmin, new UserGroup, 'mixed', null, [RoleEnum::SuperAdmin->value], [$member->id]))
        ->toThrow(ExclusiveSuperAdminException::class);

    expect(UserGroup::query()->where('name', 'mixed')->exists())->toBeFalse();
});

it('refuses an admin who deletes the group that withheld a member\'s super-admin bypass', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $mixedHolder = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $group = UserGroup::query()->create(['name' => 'readers']);
    $group->roles()->sync(Role::query()->where('name', RoleEnum::Reader->value)->pluck('id'));
    $group->users()->sync([$mixedHolder->id]);

    expect(fn () => app(DeleteUserGroupAction::class)->execute($admin, $group))
        ->toThrow(ExclusiveSuperAdminException::class);

    expect(UserGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

it('lets a trusted system write and a super-admin actor create a super-admin', function () {
    $superAdmin = User::query()->sole();

    $bySystem = app(CreateUserAction::class)->execute(null, 'System', null, 'system@example.test', null, 'secret-password-123', [RoleEnum::SuperAdmin->value]);
    $byActor = app(CreateUserAction::class)->execute($superAdmin, 'Actor', null, 'actor@example.test', null, 'secret-password-123', [RoleEnum::SuperAdmin->value]);

    expect($bySystem->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($byActor->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();
});

it('answers a refused role write over HTTP as a form error on roles', function () {
    $superAdmin = User::query()->sole();
    $member = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    // Past the form's own check: the group roles are valid alone, the member's direct role is not.
    $this->mock(SaveUserGroupAction::class)
        ->shouldReceive('execute')
        ->andThrow(new ExclusiveSuperAdminException('refused'));

    $this->actingAs($superAdmin)
        ->from(route('groups.index'))
        ->post(route('groups.store'), ['name' => 'mixed', 'roles' => [RoleEnum::Reader->value], 'users' => [$member->id]])
        ->assertSessionHasErrors('roles');
});
