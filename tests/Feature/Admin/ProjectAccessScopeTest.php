<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AssistantQueryService;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/**
 * A reader in a user group scoped to one project group holding an admin-only project.
 *
 * @return array{reader: User, outsider: User, scoped: Project, hidden: Project, shared: Project, userGroup: UserGroup, projectGroup: ProjectGroup}
 */
function scopedAccessFixture(): array
{
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $outsider = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $scoped = Project::factory()->create(['name' => 'Client A', 'visibility' => ProjectVisibilityEnum::Admins]);
    $hidden = Project::factory()->create(['name' => 'Client B', 'visibility' => ProjectVisibilityEnum::Admins]);
    $shared = Project::factory()->create(['name' => 'Shared']);

    $projectGroup = ProjectGroup::query()->create(['name' => 'Client A']);
    $projectGroup->projects()->sync([$scoped->id]);
    $userGroup = UserGroup::query()->create(['name' => 'Client A team']);
    $userGroup->users()->sync([$reader->id]);
    $userGroup->projectGroups()->sync([$projectGroup->id]);

    return compact('reader', 'outsider', 'scoped', 'hidden', 'shared', 'userGroup', 'projectGroup');
}

it('shows an admin-only project to the members of a user group scoped to its project group', function () {
    ['reader' => $reader, 'outsider' => $outsider, 'scoped' => $scoped, 'hidden' => $hidden] = scopedAccessFixture();

    expect(Project::query()->visibleTo($reader)->orderBy('name')->pluck('name')->all())->toBe(['Client A', 'Shared'])
        ->and(Project::query()->visibleTo($outsider)->pluck('name')->all())->toBe(['Shared'])
        ->and(Project::query()->visibleTo(null)->pluck('name')->all())->toBe(['Shared'])
        ->and($reader->can('view', $scoped))->toBeTrue()
        ->and($reader->can('view', $hidden))->toBeFalse()
        ->and($outsider->can('view', $scoped))->toBeFalse();

    $this->actingAs($reader)->get(route('projects.show', $scoped->slug))->assertOk();
    $this->actingAs($reader)->get(route('projects.show', $hidden->slug))->assertForbidden();
    $this->actingAs($outsider)->get(route('projects.show', $scoped->slug))->assertForbidden();
});

it('applies the scope to the lists, the picker, the fleet and the AI tools', function () {
    ['reader' => $reader] = scopedAccessFixture();

    $this->actingAs($reader)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('projects', 2));
    $this->actingAs($reader)->getJson(route('projects.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
    $this->actingAs($reader)->get(route('fleet.show'))->assertOk()
        ->assertSee('Client A')->assertDontSee('Client B');

    $names = array_column(app(AssistantQueryService::class)->forUser($reader)->projects(), 'name');
    expect($names)->toBe(['Client A', 'Shared']);
});

it('drops the access when the member leaves, the scope is removed or the project leaves the group', function () {
    ['reader' => $reader, 'scoped' => $scoped, 'userGroup' => $userGroup, 'projectGroup' => $projectGroup] = scopedAccessFixture();

    $projectGroup->projects()->detach($scoped->id);
    expect($reader->can('view', $scoped))->toBeFalse();

    $projectGroup->projects()->attach($scoped->id);
    $userGroup->projectGroups()->detach();
    expect($reader->can('view', $scoped))->toBeFalse();

    $userGroup->projectGroups()->attach($projectGroup->id);
    $userGroup->users()->detach();
    expect($reader->can('view', $scoped))->toBeFalse();

    $userGroup->users()->attach($reader->id);
    $projectGroup->delete();
    expect($reader->can('view', $scoped))->toBeFalse();
});

it('keeps the permissions global: the scope grants where, not what', function () {
    ['reader' => $reader, 'scoped' => $scoped] = scopedAccessFixture();

    expect($reader->can('update', $scoped))->toBeFalse()
        ->and($reader->can('changeVisibility', $scoped))->toBeFalse();

    Role::findOrCreate('editor')->givePermissionTo([PermissionEnum::CanShowProjects->value, PermissionEnum::CanEditProjects->value]);
    $reader->assignRole('editor');

    expect($reader->fresh()->can('update', $scoped))->toBeTrue()
        ->and($reader->fresh()->can('changeVisibility', $scoped))->toBeFalse();
});

it('lets an admin set the project scope of a user group from the form', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $member = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $project = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    $projectGroup = ProjectGroup::query()->create(['name' => 'Client A']);
    $projectGroup->projects()->sync([$project->id]);

    $this->actingAs($admin)->get(route('groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canChangeProjectScope', true)
            ->where('projectGroups', [['id' => $projectGroup->id, 'name' => 'Client A']]));

    $this->actingAs($admin)->post(route('groups.store'), [
        'name' => 'Client A team',
        'roles' => [],
        'users' => [$member->id],
        'project_groups' => [$projectGroup->id],
    ])->assertRedirect(route('groups.index'));

    $userGroup = UserGroup::query()->where('name', 'Client A team')->sole();

    expect($member->can('view', $project))->toBeTrue()
        ->and(Activity::query()->where('event', 'project_groups_synced')->where('subject_id', $userGroup->id)->exists())->toBeTrue();

    $this->actingAs($admin)->get(route('groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.0.project_groups', [['id' => $projectGroup->id, 'name' => 'Client A']]));

    $this->actingAs($admin)->put(route('groups.update', $userGroup), [
        'name' => 'Client A team',
        'roles' => [],
        'users' => [$member->id],
        'project_groups' => [],
    ])->assertRedirect(route('groups.index'));

    expect($member->fresh()->can('view', $project))->toBeFalse();
});

it('lets only admins change the project scope, and keeps it when the field is left out', function () {
    ['userGroup' => $userGroup, 'projectGroup' => $projectGroup, 'reader' => $reader, 'scoped' => $scoped] = scopedAccessFixture();
    Role::findOrCreate('group-editor')->givePermissionTo([
        PermissionEnum::CanShowGroups->value,
        PermissionEnum::CanEditGroups->value,
        PermissionEnum::CanCreateGroups->value,
    ]);
    $editor = User::factory()->create()->assignRole('group-editor');
    $other = ProjectGroup::query()->create(['name' => 'Client B']);

    $this->actingAs($editor)->get(route('groups.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canChangeProjectScope', false));

    $this->actingAs($editor)->put(route('groups.update', $userGroup), [
        'name' => 'Client A team', 'roles' => [], 'users' => [$reader->id], 'project_groups' => [$projectGroup->id, $other->id],
    ])->assertSessionHasErrors('project_groups');

    $this->actingAs($editor)->post(route('groups.store'), [
        'name' => 'New team', 'roles' => [], 'users' => [], 'project_groups' => [$other->id],
    ])->assertSessionHasErrors('project_groups');

    // Unchanged scope, or no scope field at all: saved, scope kept.
    $this->actingAs($editor)->put(route('groups.update', $userGroup), [
        'name' => 'Client A crew', 'roles' => [], 'users' => [$reader->id], 'project_groups' => [$projectGroup->id],
    ])->assertSessionHasNoErrors();
    $this->actingAs($editor)->put(route('groups.update', $userGroup), [
        'name' => 'Client A crew', 'roles' => [], 'users' => [$reader->id],
    ])->assertSessionHasNoErrors();

    expect($userGroup->projectGroups()->pluck('project_groups.id')->all())->toBe([$projectGroup->id])
        ->and($reader->can('view', $scoped))->toBeTrue();
});

it('stops a non-admin from joining a user group that carries a project scope', function () {
    ['userGroup' => $userGroup, 'reader' => $reader, 'projectGroup' => $projectGroup] = scopedAccessFixture();
    Role::findOrCreate('group-editor')->givePermissionTo([PermissionEnum::CanShowGroups->value, PermissionEnum::CanEditGroups->value]);
    $editor = User::factory()->create()->assignRole('group-editor');

    $this->actingAs($editor)->put(route('groups.update', $userGroup), [
        'name' => 'Client A team', 'roles' => [], 'users' => [$reader->id, $editor->id], 'project_groups' => [$projectGroup->id],
    ])->assertSessionHasErrors('users');

    expect($userGroup->users()->whereKey($editor->id)->exists())->toBeFalse();
});
