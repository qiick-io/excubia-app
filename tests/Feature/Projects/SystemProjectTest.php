<?php

declare(strict_types=1);

use App\Actions\RunSystemReportAction;
use App\Ai\Tools\ListProjectsTool;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Jobs\RunSystemReportJob;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemProjectSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request as ToolRequest;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;

/**
 * The system project (#70): Excubia watching itself. Seeded, never deleted, visible to everyone
 * until an admin restricts it, reported locally with no push and no network call.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // Every data source is "down": the checks are skipped and nothing leaves the test.
    app()->bind(HttpClientContract::class, fn (): HttpClientContract => new class implements HttpClientContract
    {
        public function getJson(string $url, array $queryParameters = [], array $headers = []): array
        {
            throw new DataSourceUnavailableException('test', 'offline in tests');
        }

        public function postJson(string $url, array $payload, array $headers = []): array
        {
            throw new DataSourceUnavailableException('test', 'offline in tests');
        }
    });
});

function systemProject(): Project
{
    test()->seed(SystemProjectSeeder::class);

    return Project::query()->where('is_system', true)->sole();
}

function systemActor(RoleEnum $role): User
{
    return User::factory()->create()->assignRole($role->value);
}

it('seeds one system project, visible to everyone, idempotently', function () {
    $this->seed(SystemProjectSeeder::class);
    $this->seed(SystemProjectSeeder::class);

    $project = Project::query()->where('is_system', true)->sole();

    expect($project->name)->toBe('Excubia')
        ->and($project->slug)->toBe('excubia')
        ->and($project->is_enabled)->toBeTrue()
        ->and($project->visibility)->toBe(ProjectVisibilityEnum::Everyone)
        ->and($project->push_token_hash)->toBeNull();
});

it('picks another slug when excubia is taken', function () {
    Project::factory()->create(['slug' => 'excubia']);

    expect(systemProject()->slug)->toBe('excubia-system');
});

it('never deletes the system project nor gives it a push token', function () {
    $project = systemProject();
    $admin = systemActor(RoleEnum::SuperAdmin);

    $this->actingAs($admin)->delete(route('projects.destroy', $project))->assertForbidden();
    $this->actingAs($admin)->post(route('projects.token.rotate', $project))->assertForbidden();
    $this->actingAs($admin)->delete(route('projects.token.revoke', $project))->assertForbidden();

    expect($project->fresh())->not->toBeNull()
        ->and($project->fresh()->push_token_hash)->toBeNull();
});

it('keeps the system project name but lets it be disabled', function () {
    $project = systemProject();

    $this->actingAs(systemActor(RoleEnum::Admin))
        ->put(route('projects.update', $project), ['name' => 'Renamed', 'is_enabled' => false])
        ->assertRedirect(route('projects.index'));

    expect($project->fresh()->name)->toBe('Excubia')
        ->and($project->fresh()->is_enabled)->toBeFalse();
});

it('lets only admins restrict a project to admins', function () {
    $project = systemProject();
    Role::create(['name' => 'project-manager'])->givePermissionTo(['can-show-projects', 'can-create-projects', 'can-edit-projects', 'can-delete-projects']);
    $manager = User::factory()->create()->assignRole('project-manager');

    $this->actingAs($manager)
        ->put(route('projects.update', $project), ['is_enabled' => true, 'visibility' => 'admins'])
        ->assertSessionHasErrors('visibility');

    $this->actingAs(systemActor(RoleEnum::Admin))
        ->put(route('projects.update', $project), ['is_enabled' => true, 'visibility' => 'admins'])
        ->assertSessionHasNoErrors();

    expect($project->fresh()->visibility)->toBe(ProjectVisibilityEnum::Admins);
});

it('hides a project restricted to admins from everyone else, assistant included', function () {
    $project = systemProject();
    $project->update(['visibility' => ProjectVisibilityEnum::Admins]);
    $reader = systemActor(RoleEnum::Reader);
    $admin = systemActor(RoleEnum::Admin);

    $this->actingAs($reader)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 0));
    $this->actingAs($reader)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('projects', 0));
    $this->actingAs($reader)->get(route('projects.show', $project->slug))->assertForbidden();
    expect((string) (new ListProjectsTool($reader))->handle(new ToolRequest([])))->not->toContain('excubia');

    $this->actingAs($admin)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('canChangeVisibility', true));
    $this->actingAs($admin)->get(route('projects.show', $project->slug))->assertOk();
    expect((string) (new ListProjectsTool($admin))->handle(new ToolRequest([])))->toContain('excubia');
});

it('stores the local report on the system project with no network call', function () {
    $project = systemProject();

    $report = app(RunSystemReportAction::class)->execute();

    expect($report)->not->toBeNull()
        ->and($report->project_id)->toBe($project->id)
        ->and($report->report['schemaVersion'])->toBe(1)
        ->and(collect($report->report['checks'])->firstWhere('checkId', 'laravel-pending-migrations')['status'] ?? null)->not->toBe('skipped')
        ->and($project->fresh()->last_report_at)->not->toBeNull();

    $this->artisan('excubia:system-report')->assertSuccessful();
    expect($project->healthReports()->count())->toBe(2);
});

it('skips the local report when the system project is disabled', function () {
    systemProject()->update(['is_enabled' => false]);

    expect(app(RunSystemReportAction::class)->execute())->toBeNull();
});

it('refreshes the system report from its page for project managers only', function () {
    Queue::fake();
    $project = systemProject();
    $other = Project::factory()->create();

    $this->actingAs(systemActor(RoleEnum::Admin))->get(route('projects.show', $project->slug))
        ->assertInertia(fn (Assert $page) => $page->where('canRefreshReport', true));
    $this->actingAs(systemActor(RoleEnum::Admin))->post(route('projects.system-report', $project))
        ->assertRedirect(route('projects.show', $project->slug));
    $this->actingAs(systemActor(RoleEnum::Reader))->post(route('projects.system-report', $project))->assertForbidden();
    $this->actingAs(systemActor(RoleEnum::Admin))->post(route('projects.system-report', $other))->assertForbidden();

    Queue::assertPushed(RunSystemReportJob::class, 1);
});
