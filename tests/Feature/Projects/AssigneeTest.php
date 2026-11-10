<?php

declare(strict_types=1);

use App\Enums\FindingEventEnum;
use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\ProjectAssigneeService;
use App\Services\ReportAlertsService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Who is on an exception group or a finding (#254): assign and unassign from the drawers, only
 * to users who can see the project, recorded in the activity log and the finding history, an
 * "Assigned to me" filter fed by the lists and a dashboard counter.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-10 09:00:00');
});

function assigneeUser(RoleEnum $role, string $firstName): User
{
    return User::factory()->create(['first_name' => $firstName, 'last_name' => 'Tester'])->assignRole($role->value);
}

function assigneeException(Project $project, string $fingerprint = 'a'): ExceptionGroup
{
    return ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat($fingerprint, 64), 'environment' => 'production',
        'class' => 'RuntimeException', 'message' => 'Payment failed', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(), 'status' => 'open',
    ]);
}

function assigneeFinding(Project $project, string $subject = 'vendor/package'): Finding
{
    return $project->findings()->create([
        'fingerprint' => hash('sha256', $subject), 'check_id' => 'composer-audit', 'severity' => 'high', 'status' => 'open',
        'finding' => ['checkId' => 'composer-audit', 'checkName' => 'Composer audit', 'severity' => 'high', 'status' => 'fail', 'subject' => $subject, 'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null],
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(),
    ]);
}

it('assigns and unassigns an exception group, logs it and shows it on the Exceptions tab', function () {
    $admin = assigneeUser(RoleEnum::Admin, 'Ada');
    $reader = assigneeUser(RoleEnum::Reader, 'Grace');
    $project = Project::factory()->create(['slug' => 'shop']);
    $group = assigneeException($project);

    $this->actingAs($admin)->put(route('projects.exceptions.assignee', [$project, $group]), ['assigned_user_id' => $reader->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($group->fresh()->assigned_user_id)->toBe($reader->id)
        ->and(Activity::query()->where('event', 'assigned')->where('subject_id', $group->id)->value('properties')->get('assigned_user_id'))
        ->toBe($reader->id);

    $this->actingAs($admin)->get(route('projects.view', ['project' => 'shop', 'view' => 'exceptions']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('exceptions.0.assignee', ['id' => $reader->id, 'name' => 'Grace Tester'])
            ->where('assignees', fn ($assignees) => collect($assignees)->pluck('name')->sort()->values()->all() === ['Ada Tester', 'Grace Tester']));

    $this->actingAs($admin)->put(route('projects.exceptions.assignee', [$project, $group]), ['assigned_user_id' => null])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->assigned_user_id)->toBeNull()
        ->and(Activity::query()->where('event', 'unassigned')->where('subject_id', $group->id)->exists())->toBeTrue();
});

it('assigns a finding and records who assigned it to whom in its history', function () {
    $admin = assigneeUser(RoleEnum::Admin, 'Ada');
    $reader = assigneeUser(RoleEnum::Reader, 'Grace');
    $project = Project::factory()->create(['slug' => 'shop']);
    $finding = assigneeFinding($project);

    $this->actingAs($admin)->put(route('projects.findings.assignee', [$project, $finding]), ['assigned_user_id' => $reader->id])
        ->assertSessionHasNoErrors();
    // The same assignee again changes and records nothing.
    $this->actingAs($admin)->put(route('projects.findings.assignee', [$project, $finding]), ['assigned_user_id' => $reader->id])
        ->assertSessionHasNoErrors();

    expect($finding->fresh()->assigned_user_id)->toBe($reader->id)
        ->and($finding->events()->where('event', FindingEventEnum::Assigned->value)->count())->toBe(1);

    $this->actingAs($admin)->put(route('projects.findings.assignee', [$project, $finding]), ['assigned_user_id' => null]);

    expect($finding->events()->pluck('event')->map->value->all())->toBe(['assigned', 'unassigned']);

    $this->actingAs($admin)->put(route('projects.findings.assignee', [$project, $finding]), ['assigned_user_id' => $reader->id]);
    $this->actingAs($admin)->get(route('projects.view', ['project' => 'shop', 'view' => 'findings']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('findings.0.lifecycle.assignee', ['id' => $reader->id, 'name' => 'Grace Tester'])
            ->where('findings.0.lifecycle.history.2.event', 'assigned')
            ->where('findings.0.lifecycle.history.2.assignee', 'Grace Tester')
            ->where('findings.0.lifecycle.history.2.by', 'Ada Tester'));
});

it('only assigns to active users who can see the project, including through a project scope', function () {
    $admin = assigneeUser(RoleEnum::Admin, 'Ada');
    $outsider = assigneeUser(RoleEnum::Reader, 'Grace');
    $scoped = assigneeUser(RoleEnum::Reader, 'Linus');
    $inactive = User::factory()->create(['first_name' => 'Old', 'is_active' => false])->assignRole(RoleEnum::Admin->value);
    $project = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    $group = assigneeException($project);

    $projectGroup = ProjectGroup::query()->create(['name' => 'Client A']);
    $projectGroup->projects()->sync([$project->id]);
    $userGroup = UserGroup::query()->create(['name' => 'Client A team']);
    $userGroup->users()->sync([$scoped->id]);
    $userGroup->projectGroups()->sync([$projectGroup->id]);

    expect(array_column(app(ProjectAssigneeService::class)->optionsFor($project), 'name'))->toBe(['Ada Tester', 'Linus Tester']);

    foreach ([$outsider, $inactive] as $user) {
        $this->actingAs($admin)->put(route('projects.exceptions.assignee', [$project, $group]), ['assigned_user_id' => $user->id])
            ->assertSessionHasErrors('assigned_user_id');
    }

    $this->actingAs($admin)->put(route('projects.exceptions.assignee', [$project, $group]), ['assigned_user_id' => $scoped->id])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->assigned_user_id)->toBe($scoped->id);
});

it('needs the permission of the item, a visible project and an item of the project', function () {
    $reader = assigneeUser(RoleEnum::Reader, 'Grace');
    $admin = assigneeUser(RoleEnum::Admin, 'Ada');
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);
    $group = assigneeException($project);
    $finding = assigneeFinding($project);

    $this->actingAs($reader)->put(route('projects.exceptions.assignee', [$project, $group]), ['assigned_user_id' => $reader->id])->assertForbidden();
    $this->actingAs($reader)->put(route('projects.findings.assignee', [$project, $finding]), ['assigned_user_id' => $reader->id])->assertForbidden();
    $this->actingAs($admin)->put(route('projects.exceptions.assignee', [$other, $group]), ['assigned_user_id' => null])->assertForbidden();

    Role::findOrCreate('triager')->givePermissionTo([PermissionEnum::CanShowProjects->value, PermissionEnum::CanManageExceptions->value, PermissionEnum::CanDismissFindings->value]);
    $triager = assigneeUser(RoleEnum::Reader, 'Linus')->assignRole('triager');
    $hiddenGroup = assigneeException($hidden, 'b');

    $this->actingAs($triager)->put(route('projects.exceptions.assignee', [$hidden, $hiddenGroup]), ['assigned_user_id' => null])->assertForbidden();
    $this->actingAs($triager)->put(route('projects.findings.assignee', [$project, $finding]), ['assigned_user_id' => $triager->id])->assertSessionHasNoErrors();
    $this->actingAs($triager)->put(route('projects.exceptions.assignee', [$project, $group]), [])->assertSessionHasErrors('assigned_user_id');
});

it('counts what is assigned to the user and still open on the dashboard', function () {
    $reader = assigneeUser(RoleEnum::Reader, 'Grace');
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);

    assigneeException($shop, 'a')->forceFill(['assigned_user_id' => $reader->id])->save();
    assigneeException($shop, 'b')->forceFill(['assigned_user_id' => $reader->id, 'status' => 'resolved'])->save();
    assigneeFinding($shop, 'one/package')->forceFill(['assigned_user_id' => $reader->id])->save();
    assigneeFinding($shop, 'two/package')->forceFill(['assigned_user_id' => $reader->id, 'dismissed_at' => Date::now()])->save();
    assigneeFinding($blog, 'three/package');
    assigneeException($hidden, 'c')->forceFill(['assigned_user_id' => $reader->id])->save();

    $this->actingAs($reader)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('assignedToMe', [
            ['slug' => 'shop', 'name' => 'Shop', 'exceptions' => 1, 'findings' => 1],
        ]));
});

it('names the assignee in the alerts of a finding', function () {
    $reader = assigneeUser(RoleEnum::Reader, 'Grace');
    $project = Project::factory()->create();
    $finding = assigneeFinding($project);
    $finding->forceFill(['assigned_user_id' => $reader->id])->save();

    $method = new ReflectionMethod(ReportAlertsService::class, 'assigneesByFingerprint');
    $assignees = $method->invoke(app(ReportAlertsService::class), $project, [['fingerprint' => $finding->fingerprint], ['fingerprint' => 'other']]);

    expect($assignees)->toBe([$finding->fingerprint => 'Grace Tester']);
});
