<?php

declare(strict_types=1);

use App\Data\AlertFilterData;
use App\Enums\AlertEventEnum;
use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function projectGroupActor(RoleEnum $role): User
{
    return User::factory()->create()->assignRole($role->value);
}

/**
 * A non-admin who may manage project groups and see projects, but not admin-only projects.
 */
function projectGroupManager(): User
{
    Role::findOrCreate('group-manager')->givePermissionTo([
        PermissionEnum::CanShowProjects->value,
        PermissionEnum::CanShowProjectGroups->value,
        PermissionEnum::CanCreateProjectGroups->value,
        PermissionEnum::CanEditProjectGroups->value,
        PermissionEnum::CanDeleteProjectGroups->value,
    ]);

    return User::factory()->create()->assignRole('group-manager');
}

function projectGroupWith(string $name, array $projects): ProjectGroup
{
    $projectGroup = ProjectGroup::query()->create(['name' => $name]);
    $projectGroup->projects()->sync(collect($projects)->pluck('id'));

    return $projectGroup;
}

it('keeps project group pages behind can-*-project-groups', function () {
    $reader = projectGroupActor(RoleEnum::Reader);
    $projectGroup = projectGroupWith('Acme', []);

    $this->actingAs($reader)->get(route('project-groups.index'))->assertForbidden();
    $this->actingAs($reader)->post(route('project-groups.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($reader)->put(route('project-groups.update', $projectGroup), ['name' => 'x'])->assertForbidden();
    $this->actingAs($reader)->delete(route('project-groups.destroy', $projectGroup))->assertForbidden();
});

it('creates, updates and deletes a project group with its projects', function () {
    $admin = projectGroupActor(RoleEnum::Admin);
    [$shop, $blog, $api] = Project::factory()->count(3)->create();

    $this->actingAs($admin)->post(route('project-groups.store'), [
        'name' => 'Acme',
        'description' => 'Client Acme',
        'projects' => [$shop->id, $blog->id],
    ])->assertRedirect(route('project-groups.index'));

    $projectGroup = ProjectGroup::query()->where('name', 'Acme')->sole();

    expect($projectGroup->description)->toBe('Client Acme')
        ->and($projectGroup->projects()->pluck('projects.id')->sort()->values()->all())->toBe([$shop->id, $blog->id]);

    $this->actingAs($admin)->put(route('project-groups.update', $projectGroup), [
        'name' => 'Acme Inc',
        'projects' => [$api->id],
    ])->assertRedirect(route('project-groups.index'));

    expect($projectGroup->fresh()->name)->toBe('Acme Inc')
        ->and($projectGroup->projects()->pluck('projects.id')->all())->toBe([$api->id]);

    $this->actingAs($admin)->get(route('project-groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/project-groups/index')
            ->where('projectGroups.data.0.name', 'Acme Inc')
            ->where('projectGroups.data.0.projects.0.id', $api->id));

    $this->actingAs($admin)->delete(route('project-groups.destroy', $projectGroup))->assertRedirect(route('project-groups.index'));

    expect(ProjectGroup::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(3);
});

it('validates the name and accepts only projects the actor can see', function () {
    $manager = projectGroupManager();
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    projectGroupWith('Taken', []);

    $this->actingAs($manager)->post(route('project-groups.store'), [
        'name' => 'Taken',
        'projects' => [$hidden->id],
    ])->assertSessionHasErrors(['name', 'projects.0']);
});

it('keeps admin-only projects when a non-admin edits the group', function () {
    $manager = projectGroupManager();
    $visible = Project::factory()->create();
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    $projectGroup = projectGroupWith('Acme', [$visible, $hidden]);

    $this->actingAs($manager)->get(route('project-groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projectGroups.data.0.projects', 1)
            ->where('projectGroups.data.0.projects.0.id', $visible->id));

    $this->actingAs($manager)->put(route('project-groups.update', $projectGroup), [
        'name' => 'Acme',
        'projects' => [],
    ])->assertRedirect(route('project-groups.index'));

    expect($projectGroup->projects()->pluck('projects.id')->all())->toBe([$hidden->id]);
});

it('answers the project picker with the visible projects as JSON', function () {
    $manager = projectGroupManager();
    $visible = Project::factory()->create(['name' => 'Shop']);
    Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);

    $this->actingAs($manager)->getJson(route('projects.index', ['search' => 'Sho']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.name', 'Shop');
});

it('narrows the dashboard to the projects of one group', function () {
    $reader = projectGroupActor(RoleEnum::Reader);
    $shop = Project::factory()->create(['name' => 'Shop']);
    Project::factory()->create(['name' => 'Blog']);
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    $acme = projectGroupWith('Acme', [$shop]);
    projectGroupWith('Internal', [$hidden]);

    $this->actingAs($reader)->get(route('dashboard', ['group' => $acme->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.group', $acme->id)
            ->has('projects', 1)
            ->where('projects.0.slug', $shop->slug)
            // A group with no project the reader can see is not offered.
            ->has('projectGroups', 1)
            ->where('projectGroups.0.name', 'Acme'));

    $this->actingAs($reader)->get(route('dashboard', ['group' => 999]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.group', null)
            ->has('projects', 2));
});

it('matches an alert filter by project group, including projects added later', function () {
    $shop = Project::factory()->create();
    $blog = Project::factory()->create();
    $acme = projectGroupWith('Acme', [$shop]);
    $filter = new AlertFilterData(SeverityEnum::Info, AlertEventEnum::selectableValues(), [], [$acme->id]);
    $event = AlertEventEnum::from(AlertEventEnum::selectableValues()[0]);

    expect($filter->accepts($event, SeverityEnum::Critical, $shop))->toBeTrue()
        ->and($filter->accepts($event, SeverityEnum::Critical, $blog))->toBeFalse();

    $acme->projects()->attach($blog->id);

    expect($filter->accepts($event, SeverityEnum::Critical, $blog->fresh()))->toBeTrue()
        ->and(AlertFilterData::fromStored($filter->toArray(), SeverityEnum::High)->projectGroupIds)->toBe([$acme->id])
        ->and((new AlertFilterData(SeverityEnum::Info, AlertEventEnum::selectableValues()))->accepts($event, SeverityEnum::Critical, $blog))->toBeTrue();
});
