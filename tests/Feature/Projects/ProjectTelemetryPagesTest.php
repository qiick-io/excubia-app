<?php

declare(strict_types=1);

use App\Enums\ExceptionStatusEnum;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Runtime (#24) and exceptions (#26) on the project page.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-05 12:30:00');
});

function telemetryViewer(RoleEnum $role = RoleEnum::Reader): User
{
    return User::factory()->create()->assignRole($role->value);
}

function runtimeRow(Project $project, string $type, array $dimensions, array $values = []): RuntimeAggregate
{
    return RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => $type,
        'bucket' => $values['bucket'] ?? '2026-11-05 11:58:00',
        'sample_rate' => $values['sample_rate'] ?? 1,
        'dimension_key' => hash('sha256', (string) json_encode($dimensions).($values['bucket'] ?? '')),
        'dimensions' => $dimensions,
        'count' => $values['count'] ?? 10,
        'error_count' => $values['error_count'] ?? 0,
        'total_ms' => $values['total_ms'] ?? 1000,
        'max_ms' => $values['max_ms'] ?? 300,
    ]);
}

function exceptionRow(Project $project, array $values = []): ExceptionGroup
{
    return ExceptionGroup::query()->create([
        'project_id' => $project->id,
        'fingerprint' => $values['fingerprint'] ?? str_repeat('a', 64),
        'environment' => 'production',
        'class' => 'RuntimeException',
        'message' => 'Payment failed for [email]',
        'frames' => [
            ['file' => 'app/Actions/PayAction.php', 'line' => 12, 'function' => 'App\\Actions\\PayAction::execute', 'inApp' => true],
            ['file' => 'vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 'line' => 99, 'function' => null, 'inApp' => false],
        ],
        'previous' => ['GuzzleHttp\\Exception\\ConnectException'],
        'context_kind' => 'request',
        'context_name' => 'checkout.store',
        'count' => 4,
        'first_seen_at' => '2026-11-05 10:00:00',
        'last_seen_at' => $values['last_seen_at'] ?? '2026-11-05 12:00:00',
        'first_release' => $values['first_release'] ?? null,
        'last_release' => $values['last_release'] ?? null,
        'regressed_release' => $values['regressed_release'] ?? null,
        'status' => $values['status'] ?? 'open',
    ]);
}

it('summarises the last 24 hours of runtime on the project page', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    runtimeRow($project, 'request', ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx'], ['count' => 90, 'total_ms' => 9000, 'max_ms' => 800]);
    runtimeRow($project, 'request', ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '5xx'], ['count' => 5, 'error_count' => 5, 'total_ms' => 500, 'max_ms' => 120, 'sample_rate' => 0.5]);
    runtimeRow($project, 'request', ['route' => 'old', 'method' => 'GET', 'statusClass' => '2xx'], ['bucket' => '2026-11-03 10:00:00']);
    runtimeRow($project, 'job', ['job' => 'App\\Jobs\\Ship', 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'failed'], ['count' => 2, 'error_count' => 2]);
    runtimeRow($project, 'slowQuery', ['sql' => 'select * from orders where id = ?', 'connection' => 'pgsql'], ['count' => 3]);

    $this->actingAs(telemetryViewer())->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('runtime.isSampled', true)
            ->where('runtime.requests.count', 100)
            ->where('runtime.requests.errors', 10)
            ->where('runtime.requests.averageMs', 100)
            ->where('runtime.requests.maxMs', 800)
            ->has('runtime.requests.timeline', 25)
            ->has('runtime.requests.routes', 1)
            ->where('runtime.requests.routes.0.route', 'checkout.store')
            ->where('runtime.jobs.0.outcomes.failed', 2)
            ->where('runtime.slowQueries.0.sql', 'select * from orders where id = ?'));
});

it('shows no runtime block before the first runtime push', function () {
    Project::factory()->create(['slug' => 'blog']);

    $this->actingAs(telemetryViewer())->get(route('projects.show', 'blog'))
        ->assertInertia(fn (Assert $page) => $page->where('runtime', null)->has('exceptions', 0)->where('canManageExceptions', false));
});

it('lists exception groups open first with a fix prompt', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    exceptionRow($project, ['fingerprint' => str_repeat('b', 64), 'status' => 'resolved', 'last_seen_at' => '2026-11-05 12:20:00']);
    exceptionRow($project);

    $this->actingAs(telemetryViewer(RoleEnum::Admin))->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('exceptions', 2)
            ->where('exceptions.0.status', 'open')
            ->where('exceptions.0.context', ['kind' => 'request', 'name' => 'checkout.store'])
            ->where('exceptions.0.fix.command', null)
            ->where('exceptions.0.fix.prompt', fn (string $prompt): bool => str_contains($prompt, 'RuntimeException: Payment failed for [email]')
                && str_contains($prompt, 'app/Actions/PayAction.php:12')
                && ! str_contains($prompt, 'vendor/laravel'))
            ->where('canManageExceptions', true));
});

it('lets only exceptions.manage resolve, ignore or reopen a group of a visible project', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $other = Project::factory()->create();
    $group = exceptionRow($project);

    $this->actingAs(telemetryViewer())
        ->patch(route('projects.exceptions.update', [$project, $group]), ['status' => 'resolved'])
        ->assertForbidden();

    $admin = telemetryViewer(RoleEnum::Admin);
    $this->actingAs($admin)->patch(route('projects.exceptions.update', [$other, $group]), ['status' => 'resolved'])->assertForbidden();
    $this->actingAs($admin)->patch(route('projects.exceptions.update', [$project, $group]), ['status' => 'closed'])->assertSessionHasErrors('status');

    $this->actingAs($admin)->patch(route('projects.exceptions.update', [$project, $group]), ['status' => 'resolved'])->assertRedirect();
    expect($group->fresh())->status->toBe(ExceptionStatusEnum::Resolved)->resolved_at->not->toBeNull();

    $this->actingAs($admin)->patch(route('projects.exceptions.update', [$project, $group]), ['status' => 'open'])->assertRedirect();
    expect($group->fresh())->status->toBe(ExceptionStatusEnum::Open)->resolved_at->toBeNull();
});

it('adds runtime and open exceptions to the dashboard for the scoped projects', function () {
    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    runtimeRow($shop, 'request', ['route' => 'home', 'method' => 'GET', 'statusClass' => '2xx'], ['count' => 90]);
    runtimeRow($shop, 'request', ['route' => 'home', 'method' => 'GET', 'statusClass' => '5xx'], ['count' => 10, 'error_count' => 10]);
    runtimeRow($shop, 'request', ['route' => 'home', 'method' => 'GET', 'statusClass' => '2xx'], ['count' => 40, 'bucket' => '2026-10-15 10:00:00']);
    exceptionRow($shop);
    exceptionRow($shop, ['fingerprint' => str_repeat('c', 64), 'status' => 'ignored']);
    // The rows are written directly, so their hourly rollups (#137) come from the backfill.
    $this->artisan('excubia:backfill-runtime-rollups')->assertSuccessful();

    $this->actingAs(telemetryViewer())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.runtime', ['requests' => 100, 'errors' => 10, 'previousRequests' => 40])
            ->where('overview.exceptions.open', 1)
            ->where('overview.exceptions.new', 1)
            ->where('overview.exceptions.top.0.project.slug', 'shop'));

    $this->actingAs(telemetryViewer())->get(route('dashboard', ['project' => 'blog']))
        ->assertInertia(fn (Assert $page) => $page->where('overview.runtime', null)->where('overview.exceptions', null));
});

it('marks the deploys of the runtime window and shows releases on reports and exceptions (#106)', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    runtimeRow($project, 'request', ['route' => 'home', 'method' => 'GET', 'statusClass' => '2xx']);
    $report = fn (string $at, ?string $release, ?string $deployedAt) => $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $at,
        'generated_at' => $at,
        'release' => $release,
        'deployed_at' => $deployedAt,
        'report' => ['schemaVersion' => 1, 'checks' => [], 'upgradePlan' => []],
    ]);
    $report('2026-11-03 09:00:00', 'v0.9.0', '2026-11-03 09:00:00');
    $report('2026-11-05 11:20:00', 'v1.0.0', '2026-11-05 11:20:00');
    $report('2026-11-05 12:00:00', 'v1.0.0', null);
    exceptionRow($project, ['first_release' => 'v0.9.0', 'last_release' => 'v1.0.0', 'regressed_release' => 'v1.0.0']);

    $this->actingAs(telemetryViewer())->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('runtime.deploys', [['release' => 'v1.0.0', 'deployedAt' => '2026-11-05T11:20:00Z', 'bucket' => '2026-11-05T11:00:00Z']])
            ->where('runtime.requests.timeline', fn ($timeline): bool => collect($timeline)->contains('at', '2026-11-05T11:00:00Z'))
            ->where('reports.0.release', 'v1.0.0')
            ->where('reports.0.deployed_at', null)
            ->where('reports.1.deployed_at', '2026-11-05T11:20:00+00:00')
            ->where('exceptions.0.first_release', 'v0.9.0')
            ->where('exceptions.0.last_release', 'v1.0.0')
            ->where('exceptions.0.regressed_release', 'v1.0.0'));
});
