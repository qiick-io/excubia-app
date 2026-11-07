<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use App\Models\User;
use App\Services\AlertSuppressionService;
use App\Services\ReportAlertsService;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Minute-level health samples (#115): ingest and merge of the core `health` type, its hourly
 * rollup, the live health of the project overview and of the dashboard list, the debounced
 * `health.failed` / `health.recovered` alerts through the dispatcher (mutes apply), and the
 * follow-ups of #143: the duplicate with `infrastructure.failed`, `health.stale`, the re-alert
 * on new fingerprints and the partial reload of the poll.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Date::setTestNow('2026-11-07 10:00:30');
    app(SettingsRepositoryService::class)->save([
        'webhook_url' => 'https://hooks.example.com/excubia',
        'webhook_secret' => str_repeat('s', 32),
    ]);
});

/**
 * A health entry as excubia-core sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function healthEntry(string $bucket, string $status, array $overrides = []): array
{
    return [
        'type' => 'health',
        'bucket' => $bucket,
        'sampleRate' => 1,
        'server' => 'web-1',
        'checkId' => 'laravel-database',
        'count' => 1,
        'errorCount' => $status === 'fail' ? 1 : 0,
        'status' => $status,
        'severity' => $status === 'fail' ? 'critical' : 'info',
        'findingCount' => $status === 'fail' ? 1 : 0,
        'fingerprints' => $status === 'fail' ? [str_repeat('a', 64)] : [],
        ...$overrides,
    ];
}

/**
 * @return array{0: Project, 1: array<string, string>}
 */
function healthProject(): array
{
    $project = Project::factory()->create(['slug' => 'shop']);

    return [$project, ['Authorization' => 'Bearer '.app(RotateProjectTokenAction::class)->execute($project)]];
}

/**
 * @param  list<array<string, mixed>>  $entries
 */
function pushHealth(array $headers, array $entries): void
{
    test()->postJson(route('api.runtime.store'), [
        'protocolVersion' => 1,
        'type' => 'runtime',
        'batchId' => (string) Str::uuid(),
        'sentAt' => '2026-11-07T10:00:00Z',
        'runtime' => ['release' => 'v2', 'bucketSeconds' => 60, 'aggregates' => $entries],
    ], $headers)->assertCreated();
}

/**
 * The health alerts in the history (rotating the token logs `project.token_changed`).
 *
 * @return Builder<AlertLog>
 */
function healthAlerts(): Builder
{
    return AlertLog::query()->where('event', 'like', 'health.%');
}

/**
 * @return list<string>
 */
function healthEvents(Project $project): array
{
    return healthAlerts()->where('project_id', $project->id)->orderBy('id')->pluck('event')->all();
}

it('merges health samples of one server, check and minute by the worst status', function () {
    [$project, $headers] = healthProject();

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'pass')]);
    pushHealth($headers, [
        healthEntry('2026-11-07T09:58:00Z', 'fail', ['severity' => 'high', 'findingCount' => 2, 'fingerprints' => [str_repeat('b', 64), str_repeat('a', 64)]]),
        healthEntry('2026-11-07T09:58:00Z', 'skipped'),
        healthEntry('2026-11-07T09:58:00Z', 'warn', ['severity' => 'critical', 'findingCount' => 1, 'fingerprints' => [str_repeat('c', 64)]]),
    ]);

    $row = $project->runtimeAggregates()->sole();

    expect($row->dimensions)->toBe(['server' => 'web-1', 'checkId' => 'laravel-database'])
        ->and([$row->count, $row->error_count])->toBe([4, 1])
        ->and($row->metrics)->toBe([
            'status' => 'fail',
            'severity' => 'critical',
            'findingCount' => 2,
            'fingerprints' => [str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)],
        ]);

    // Health counts in the hourly rollups like every other type (#137).
    expect(RuntimeHourlyRollup::query()->where('type', 'health')->sole()->count)->toBe(4)
        ->and($project->fresh()?->skipped_runtime_aggregates)->toBe(0);
});

it('accepts unknown check ids and rejects a broken health entry', function () {
    [$project, $headers] = healthProject();

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'pass', ['checkId' => 'laravel-future-probe'])]);

    expect(RuntimeAggregate::query()->sole()->dimensions['checkId'])->toBe('laravel-future-probe');

    foreach ([['status', 'down'], ['severity', 'urgent'], ['checkId', 'Laravel Database'], ['fingerprints', ['nope']], ['findingCount', -1]] as [$field, $value]) {
        $this->postJson(route('api.runtime.store'), [
            'protocolVersion' => 1, 'type' => 'runtime', 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-07T10:00:00Z',
            'runtime' => ['bucketSeconds' => 60, 'aggregates' => [healthEntry('2026-11-07T09:59:00Z', 'pass', [$field => $value])]],
        ], $headers)->assertUnprocessable();
    }
});

it('alerts health.failed after two failing readings of a server, once, and recovers on the first reading without a failure', function () {
    [$project, $headers] = healthProject();

    pushHealth($headers, [healthEntry('2026-11-07T09:55:00Z', 'pass'), healthEntry('2026-11-07T09:56:00Z', 'fail')]);
    expect(healthEvents($project))->toBe([]);

    // A skipped minute has no reading: it neither breaks nor counts towards the debounce.
    pushHealth($headers, [healthEntry('2026-11-07T09:57:00Z', 'skipped')]);
    expect(healthEvents($project))->toBe([]);

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail')]);
    $failed = healthAlerts()->sole();

    expect($failed->event)->toBe('health.failed')
        ->and($failed->severity)->toBe('critical')
        ->and($failed->subject)->toBe('laravel-database')
        ->and($failed->summary)->toBe('laravel-database fails on 1 of 1 servers');
    Queue::assertPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => $job->payload['event'] === 'health.failed'
        && $job->payload['data']->failingServers === 1
        && $job->payload['data']->fingerprints === [str_repeat('a', 64)]);

    // Still failing, and skipped: no new alert, and skipped is no recovery.
    pushHealth($headers, [healthEntry('2026-11-07T09:59:00Z', 'fail'), healthEntry('2026-11-07T10:00:00Z', 'skipped')]);
    expect(healthEvents($project))->toBe(['health.failed']);

    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'warn', ['severity' => 'medium'])]);
    expect(healthEvents($project))->toBe(['health.failed', 'health.recovered'])
        ->and(healthAlerts()->latest('id')->first()?->summary)->toBe('laravel-database no longer fails (warn)');
});

it('keeps a check failing while another server still fails, and never reads silence as recovery', function () {
    [$project, $headers] = healthProject();

    pushHealth($headers, [
        healthEntry('2026-11-07T09:57:00Z', 'fail', ['server' => 'web-2']),
        healthEntry('2026-11-07T09:58:00Z', 'fail', ['server' => 'web-2']),
        healthEntry('2026-11-07T09:58:00Z', 'pass'),
    ]);
    expect(healthEvents($project))->toBe(['health.failed'])
        ->and(healthAlerts()->sole()->summary)->toBe('laravel-database fails on 1 of 2 servers');

    // web-2 stops sending while failing: web-1 passing is no recovery.
    pushHealth($headers, [healthEntry('2026-11-07T09:59:00Z', 'pass'), healthEntry('2026-11-07T10:00:00Z', 'pass')]);
    expect(healthEvents($project))->toBe(['health.failed']);

    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'pass', ['server' => 'web-2'])]);
    expect(healthEvents($project))->toBe(['health.failed', 'health.recovered']);
});

it('names the failing finding of the latest report by fingerprint', function () {
    [$project, $headers] = healthProject();
    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(), 'received_at' => '2026-11-07 08:00:00', 'generated_at' => '2026-11-07 08:00:00',
        'report' => ['schemaVersion' => 1, 'checks' => [[
            'checkId' => 'laravel-database', 'name' => 'Database', 'status' => 'fail',
            'findings' => [['checkId' => 'laravel-database', 'severity' => 'critical', 'status' => 'fail', 'subject' => 'mysql', 'message' => 'Cannot connect', 'fingerprint' => str_repeat('a', 64)]],
        ]]],
    ]);

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail'), healthEntry('2026-11-07T09:59:00Z', 'fail')]);

    expect(healthAlerts()->sole()->summary)->toBe('laravel-database fails on 1 of 1 servers: mysql: Cannot connect');
});

it('suppresses a muted check but keeps its state, so the recovery still follows', function () {
    [$project, $headers] = healthProject();
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'health.failed', 'subject' => 'laravel-database', 'until' => null]);

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail'), healthEntry('2026-11-07T09:59:00Z', 'fail')]);
    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'fail')]);

    expect(healthAlerts()->sole()->status)->toBe(AlertLog::SUPPRESSED)
        ->and(healthAlerts()->sole()->reason)->toBe(AlertSuppressionService::MUTED);
    Queue::assertNotPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => str_starts_with($job->payload['event'], 'health.'));

    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'pass', ['server' => 'web-1', 'checkId' => 'laravel-cache'])]);
    expect(healthEvents($project))->toBe(['health.failed']);
});

it('shows the live health per check and server on the overview only', function () {
    $this->seed(PermissionSeeder::class);
    [$project, $headers] = healthProject();

    pushHealth($headers, [
        healthEntry('2026-11-07T09:50:00Z', 'pass', ['checkId' => 'laravel-cache', 'server' => 'worker-1']),
        healthEntry('2026-11-07T09:59:00Z', 'pass', ['checkId' => 'laravel-cache']),
        healthEntry('2026-11-07T09:59:00Z', 'warn', ['severity' => 'medium', 'findingCount' => 1]),
        healthEntry('2026-11-07T10:00:00Z', 'skipped'),
        // Outside the window.
        healthEntry('2026-11-07T08:30:00Z', 'fail', ['checkId' => 'laravel-storage']),
    ]);

    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);

    $this->actingAs($reader)->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('liveHealth', 2)
            ->where('liveHealth.0.checkId', 'laravel-database')
            ->where('liveHealth.0.status', 'warn')
            ->where('liveHealth.0.severity', 'medium')
            ->where('liveHealth.0.lastSampleAt', '2026-11-07T10:00:00Z')
            ->where('liveHealth.0.servers', [['server' => 'web-1', 'status' => 'warn', 'lastSampleAt' => '2026-11-07T10:00:00Z', 'isStale' => false]])
            ->where('liveHealth.1.checkId', 'laravel-cache')
            ->where('liveHealth.1.status', 'pass')
            ->where('liveHealth.1.servers.0.server', 'web-1')
            ->where('liveHealth.1.servers.1.server', 'worker-1')
            ->where('liveHealth.1.servers.1.isStale', true)
            ->missing('liveHealth.0.fingerprints'));

    $this->actingAs($reader)->get(route('projects.view', ['project' => 'shop', 'view' => 'runtime']))
        ->assertInertia(fn (Assert $page) => $page->where('liveHealth', []));
});

it('keeps projects without health samples on the daily report', function () {
    $this->seed(PermissionSeeder::class);
    Project::factory()->create(['slug' => 'shop']);

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page->where('liveHealth', []));

    expect(healthAlerts()->count())->toBe(0);
});

/**
 * A report whose database check fails with the finding of `$fingerprint`, its alerts raised as
 * after a push (`infrastructure.failed` for a new database failure).
 */
function reportDatabaseFailure(Project $project, string $fingerprint): void
{
    $report = $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(), 'received_at' => Date::now(), 'generated_at' => Date::now(),
        'report' => ['schemaVersion' => 1, 'checks' => [[
            'checkId' => 'laravel-database', 'name' => 'Database', 'status' => 'fail',
            'findings' => [['checkId' => 'laravel-database', 'severity' => 'critical', 'status' => 'fail', 'subject' => 'mysql', 'message' => 'Cannot connect', 'fingerprint' => $fingerprint]],
        ]]],
    ]);

    app(ReportAlertsService::class)->afterReport($project, $report, null, false);
}

/**
 * @return list<string> "event:status[:reason]" of the project's health and infrastructure alerts, oldest first
 */
function failureAlerts(Project $project): array
{
    return AlertLog::query()
        ->where('project_id', $project->id)
        ->whereIn('event', ['infrastructure.failed', 'health.failed', 'health.recovered', 'health.stale'])
        ->orderBy('id')
        ->get()
        ->map(fn (AlertLog $alert): string => rtrim("{$alert->event}:{$alert->status}:{$alert->reason}", ':'))
        ->all();
}

it('sends one alert when a report failure follows a health failure of the same check and fingerprint', function () {
    [$project, $headers] = healthProject();

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail'), healthEntry('2026-11-07T09:59:00Z', 'fail')]);
    reportDatabaseFailure($project, str_repeat('a', 64));

    expect(failureAlerts($project))->toBe(['health.failed:queued', 'infrastructure.failed:suppressed:duplicate']);
    Queue::assertNotPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => $job->payload['event'] === 'infrastructure.failed');

    // Another finding of the check, or the same one past the window, is a new alert.
    reportDatabaseFailure($project, str_repeat('f', 64));
    Date::setTestNow(Date::now()->addMinutes(AlertSuppressionService::DUPLICATE_MINUTES + 1));
    reportDatabaseFailure($project, str_repeat('a', 64));

    expect(array_slice(failureAlerts($project), 2))->toBe(['infrastructure.failed:queued', 'infrastructure.failed:queued']);
});

it('keeps a health failure that follows a report failure as a duplicate, with its state', function () {
    [$project, $headers] = healthProject();

    reportDatabaseFailure($project, str_repeat('a', 64));
    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail'), healthEntry('2026-11-07T09:59:00Z', 'fail')]);
    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'pass')]);

    expect(failureAlerts($project))->toBe(['infrastructure.failed:queued', 'health.failed:suppressed:duplicate', 'health.recovered:queued']);
    Queue::assertNotPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => $job->payload['event'] === 'health.failed');
});

it('alerts again when the failing fingerprints change while the check still fails', function () {
    [$project, $headers] = healthProject();
    [$first, $second] = [str_repeat('a', 64), str_repeat('b', 64)];

    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail'), healthEntry('2026-11-07T09:59:00Z', 'fail')]);
    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'fail', ['fingerprints' => [$second, $first]])]);
    // The same minute again: the union is still both fingerprints.
    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'fail', ['fingerprints' => [$second]])]);

    expect(failureAlerts($project))->toBe(['health.failed:queued', 'health.failed:queued'])
        ->and(healthAlerts()->orderBy('id')->pluck('fingerprints')->all())->toBe([[$first], [$first, $second]]);
    Queue::assertPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => $job->payload['event'] === 'health.failed'
        && $job->payload['data']->fingerprints === [$first, $second]);
});

it('alerts a server that stopped sending once per silence, and recovers without it', function () {
    [$project, $headers] = healthProject();
    Date::setTestNow('2026-11-07 09:55:00');
    pushHealth($headers, [
        healthEntry('2026-11-07T09:53:00Z', 'fail', ['server' => 'web-2']),
        healthEntry('2026-11-07T09:54:00Z', 'fail', ['server' => 'web-2']),
        healthEntry('2026-11-07T09:54:00Z', 'pass'),
    ]);
    expect(failureAlerts($project))->toBe(['health.failed:queued']);

    // web-2 went silent while failing: web-1's next sample recovers the check.
    Date::setTestNow('2026-11-07 10:00:30');
    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'pass')]);
    $stale = healthAlerts()->where('event', 'health.stale')->sole();

    expect(failureAlerts($project))->toBe(['health.failed:queued', 'health.recovered:queued', 'health.stale:queued'])
        ->and($stale->subject)->toBe('web-2')
        ->and($stale->severity)->toBe('medium')
        ->and($stale->summary)->toBe('no health sample from web-2 since 2026-11-07 09:54:00');

    // The minute clock does not alert the same silence twice.
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();
    expect(healthAlerts()->where('event', 'health.stale')->count())->toBe(1);

    // web-2 sends again, then stops again: a new silence.
    pushHealth($headers, [healthEntry('2026-11-07T10:00:00Z', 'pass', ['server' => 'web-2'])]);
    Date::setTestNow('2026-11-07 10:06:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();

    expect(healthAlerts()->where('event', 'health.stale')->orderBy('id')->pluck('subject')->all())->toBe(['web-2', 'web-1', 'web-2']);
});

it('never recovers when every server went silent', function () {
    [$project, $headers] = healthProject();
    pushHealth($headers, [healthEntry('2026-11-07T09:58:00Z', 'fail'), healthEntry('2026-11-07T09:59:00Z', 'fail')]);

    Date::setTestNow('2026-11-07 10:10:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();
    Date::setTestNow('2026-11-07 11:30:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();

    expect(failureAlerts($project))->toBe(['health.failed:queued', 'health.stale:queued']);
});

it('shows the live health of each project in the dashboard list and polls it alone', function () {
    $this->seed(PermissionSeeder::class);
    [$shop, $headers] = healthProject();
    Project::factory()->create(['slug' => 'blog']);
    pushHealth($headers, [
        healthEntry('2026-11-07T09:59:00Z', 'fail'),
        healthEntry('2026-11-07T09:59:00Z', 'pass', ['checkId' => 'laravel-cache']),
        healthEntry('2026-11-07T09:50:00Z', 'pass', ['checkId' => 'laravel-cache', 'server' => 'worker-1']),
    ]);
    $response = $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))->get(route('dashboard'));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response->assertInertia(fn (Assert $page) => $page
        ->where('liveHealth', ['shop' => ['status' => 'fail', 'checks' => 2, 'failingChecks' => 1, 'hasStaleServers' => true]])
        ->reloadOnly('liveHealth', fn (Assert $reload) => $reload->missing('overview')->missing('projects')));

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'health_reports')))->toBe([]);
});

it('computes only the live health when the overview polls it', function () {
    $this->seed(PermissionSeeder::class);
    [$project, $headers] = healthProject();
    pushHealth($headers, [healthEntry('2026-11-07T09:59:00Z', 'pass')]);
    $response = $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))->get(route('projects.show', 'shop'));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response->assertInertia(fn (Assert $page) => $page
        ->has('liveHealth', 1)
        ->reloadOnly('liveHealth', fn (Assert $reload) => $reload->has('liveHealth', 1)->missing('reports')->missing('runtime')));

    expect(array_filter($queries, fn (string $sql): bool => preg_match('/health_reports|exception_groups|alert_logs/', $sql) === 1))->toBe([])
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'runtime_aggregates')))->toHaveCount(1);
});
