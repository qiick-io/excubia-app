<?php

declare(strict_types=1);

use App\Actions\PruneTelemetryAction;
use App\Actions\StorePushedExceptionsAction;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The time range picker of the runtime, exceptions and schedule tabs (#113): `?range=` picks the
 * window and the chart bucket, sums are per bucket in SQL, deploy markers follow the range, and
 * each exception group has its occurrences per bucket.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-05 12:30:00');
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value));
});

function rangeRequest(Project $project, string $bucket, int $count, int $errors = 0, float $sampleRate = 1): void
{
    RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => 'request',
        'bucket' => $bucket,
        'sample_rate' => $sampleRate,
        'dimension_key' => hash('sha256', $bucket.$errors.$sampleRate),
        'dimensions' => ['route' => 'home', 'method' => 'GET', 'statusClass' => $errors > 0 ? '5xx' : '2xx'],
        'count' => $count,
        'error_count' => $errors,
        'total_ms' => $count * 10,
        'max_ms' => 50,
    ]);
}

/**
 * One request series of a status class with a latency histogram (#251, #246).
 *
 * @param  list<int>  $histogram  counts per core histogram bucket (≤5, ≤10, ≤25, ≤50, ≤100 ms, …)
 */
function rangeStatusRequest(Project $project, string $bucket, string $statusClass, int $count, int $totalMs, int $maxMs, array $histogram, float $sampleRate = 1): void
{
    RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => 'request',
        'bucket' => $bucket,
        'sample_rate' => $sampleRate,
        'dimension_key' => hash('sha256', $bucket.$statusClass.$sampleRate),
        'dimensions' => ['route' => 'home', 'method' => 'GET', 'statusClass' => $statusClass],
        'count' => $count,
        'error_count' => $statusClass === '5xx' ? $count : 0,
        'total_ms' => $totalMs,
        'max_ms' => $maxMs,
        'histogram' => array_pad($histogram, 12, 0),
    ]);
}

/**
 * An exceptions group as excubia-core pushes it.
 *
 * @return array<string, mixed>
 */
function rangeExceptionGroup(string $fingerprint, int $count, string $lastSeen): array
{
    return [
        'class' => 'RuntimeException',
        'fingerprint' => str_repeat($fingerprint, 64),
        'message' => 'Payment failed',
        'frames' => [],
        'previous' => [],
        'context' => ['kind' => 'request', 'name' => 'checkout.store'],
        'environment' => 'production',
        'count' => $count,
        'firstSeen' => '2026-10-20T08:00:00Z',
        'lastSeen' => $lastSeen,
    ];
}

it('picks the window and the chart bucket from the range', function (?string $range, int $points, string $first, int $hours) {
    rangeRequest($this->project, '2026-11-05 12:30:00', 4);

    $this->get(route('projects.view', ['shop', 'runtime', 'range' => $range]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('range', $range === 'forever' || $range === null ? '24h' : $range)
            ->where('runtime.hours', $hours)
            ->has('runtime.requests.timeline', $points)
            ->where('runtime.requests.timeline.0.at', $first)
            ->where('runtime.requests.timeline.'.($points - 1).'.ok', 4));
})->with([
    '1 h by minute' => ['1h', 61, '2026-11-05T11:30:00Z', 1],
    '6 h by 10 minutes' => ['6h', 37, '2026-11-05T06:30:00Z', 6],
    '24 h by hour' => ['24h', 25, '2026-11-04T12:00:00Z', 24],
    '7 d by 6 hours' => ['7d', 29, '2026-10-29T12:00:00Z', 168],
    '14 d by day' => ['14d', 15, '2026-10-22T00:00:00Z', 336],
    'none is 24 h' => [null, 25, '2026-11-04T12:00:00Z', 24],
    'unknown is 24 h' => ['forever', 25, '2026-11-04T12:00:00Z', 24],
]);

it('sums each bucket in SQL across minutes and sample rates and keeps the totals of the range', function () {
    rangeRequest($this->project, '2026-11-01 01:00:00', 10);
    rangeRequest($this->project, '2026-11-01 23:59:00', 5, 5, 0.5);
    rangeRequest($this->project, '2026-11-03 10:00:00', 7);
    rangeRequest($this->project, '2026-10-01 10:00:00', 1000);

    $this->get(route('projects.view', ['shop', 'runtime', 'range' => '14d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('runtime.requests.count', 27)
            ->where('runtime.requests.errors', 10)
            ->where('runtime.requests.timeline.10', ['at' => '2026-11-01T00:00:00Z', 'ok' => 10, 'clientErrors' => 0, 'serverErrors' => 10, 'averageMs' => 10, 'p95Ms' => null])
            ->where('runtime.requests.timeline.12.ok', 7));

    $this->get(route('projects.view', ['shop', 'runtime', 'range' => '1h']))
        ->assertInertia(fn (Assert $page) => $page->where('runtime.requests.count', 0));
});

it('splits each bucket into 1/2/3xx, 4xx and 5xx with the average and p95 duration (#251, #246)', function () {
    // The 12:00 bucket of the 24 h range: 60 successful, 20 client and 5 server errors (estimated).
    rangeStatusRequest($this->project, '2026-11-05 12:01:00', '2xx', 40, 1600, 90, [0, 0, 20, 20]);
    rangeStatusRequest($this->project, '2026-11-05 12:02:00', '3xx', 20, 200, 10, [0, 20]);
    // Sampled at 0.5: 10 recorded 4xx stand for 20.
    rangeStatusRequest($this->project, '2026-11-05 12:03:00', '4xx', 10, 100, 10, [0, 10], 0.5);
    rangeStatusRequest($this->project, '2026-11-05 12:04:00', '5xx', 5, 4000, 900, [0, 0, 0, 0, 0, 0, 0, 5]);

    $this->get(route('projects.view', ['shop', 'runtime', 'range' => '24h']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('runtime.requests.timeline.24.ok', 60)
            ->where('runtime.requests.timeline.24.clientErrors', 20)
            ->where('runtime.requests.timeline.24.serverErrors', 5)
            // (1600 + 200 + 100 + 4000) ms over 75 recorded requests.
            ->where('runtime.requests.timeline.24.averageMs', 78.7)
            // 85 estimated requests, cumulative 40 (≤10 ms), 60 (≤25), 80 (≤50), 85 (≤1000): rank
            // 80.75 lies in the 500–1000 ms bucket, 0.75 of its 5 requests in: 500 + 500 × 0.15.
            ->where('runtime.requests.timeline.24.p95Ms', 575)
            // An hour without requests is a gap, not a zero.
            ->where('runtime.requests.timeline.23.averageMs', null)
            ->where('runtime.requests.timeline.23.p95Ms', null));
});

it('marks the deploys of the range on the bucket they fall in', function () {
    rangeRequest($this->project, '2026-11-05 12:00:00', 1);

    foreach (['2026-10-25 09:00:00' => 'v1', '2026-11-05 12:07:00' => 'v2'] as $at => $release) {
        $this->project->healthReports()->create([
            'batch_id' => (string) Str::uuid(),
            'received_at' => $at,
            'generated_at' => $at,
            'release' => $release,
            'deployed_at' => $at,
            'report' => ['schemaVersion' => 1, 'checks' => [], 'upgradePlan' => []],
        ]);
    }

    $this->get(route('projects.view', ['shop', 'runtime', 'range' => '1h']))
        ->assertInertia(fn (Assert $page) => $page->where('runtime.deploys', [['release' => 'v2', 'deployedAt' => '2026-11-05T12:07:00Z', 'bucket' => '2026-11-05T12:07:00Z']]));

    $this->get(route('projects.view', ['shop', 'runtime', 'range' => '14d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('runtime.deploys.0.bucket', '2026-10-25T00:00:00Z')
            ->where('runtime.deploys.1.bucket', '2026-11-05T00:00:00Z'));

    $this->get(route('projects.view', ['shop', 'exceptions', 'range' => '6h']))
        ->assertInertia(fn (Assert $page) => $page->where('deploys.0.bucket', '2026-11-05T12:00:00Z'));
});

it('counts the occurrences of each exception group per minute and charts them over the range', function () {
    $store = app(StorePushedExceptionsAction::class);
    $store->execute($this->project, (string) Str::uuid(), [rangeExceptionGroup('a', 3, '2026-11-05T12:10:20Z'), rangeExceptionGroup('b', 1, '2026-10-30T08:00:00Z')]);
    $store->execute($this->project, (string) Str::uuid(), [rangeExceptionGroup('a', 2, '2026-11-05T12:10:50Z')]);
    $store->execute($this->project, (string) Str::uuid(), [rangeExceptionGroup('a', 4, '2026-11-04T09:00:00Z')]);

    $group = ExceptionGroup::query()->where('fingerprint', str_repeat('a', 64))->sole();
    expect($group->count)->toBe(9)
        ->and($group->occurrences()->orderBy('bucket')->get(['bucket', 'count'])->map(fn (ExceptionOccurrence $occurrence): array => [$occurrence->bucket->toDateTimeString(), $occurrence->count])->all())
        ->toBe([['2026-11-04 09:00:00', 4], ['2026-11-05 12:10:00', 5]]);

    $this->get(route('projects.view', ['shop', 'exceptions', 'range' => '1h']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('exceptions', 1)
            ->where('exceptions.0.count', 9)
            ->where('exceptions.0.range_count', 5)
            ->has('exceptions.0.timeline', 61)
            ->where('exceptions.0.timeline.40', ['at' => '2026-11-05T12:10:00Z', 'count' => 5]));

    $this->get(route('projects.view', ['shop', 'exceptions', 'range' => '14d']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('exceptions', 2)
            ->where('exceptions.0.range_count', 9)
            ->where('exceptions.0.timeline.13.count', 4)
            ->where('exceptions.0.timeline.14.count', 5)
            ->where('exceptions.1.range_count', 1));

    // The overview keeps every group, without the range fields.
    $this->get(route('projects.show', 'shop'))
        ->assertInertia(fn (Assert $page) => $page->has('exceptions', 2)->missing('exceptions.0.timeline'));
});

it('shows the runs of each scheduled task per outcome over the range', function () {
    $task = ScheduledTask::query()->create([
        'project_id' => $this->project->id,
        'task_id' => str_repeat('c', 64),
        'task' => 'artisan reports:send',
        'expression' => '0 * * * *',
        'timezone' => 'UTC',
        'environments' => [],
    ]);
    $run = fn (string $bucket, string $outcome, int $count, float $sampleRate = 1) => ScheduledTaskRun::query()->create([
        'project_id' => $this->project->id,
        'task_id' => $task->task_id,
        'bucket' => $bucket,
        'outcome' => $outcome,
        'count' => $count,
        'sample_rate' => $sampleRate,
    ]);
    $run('2026-11-05 12:00:00', 'finished', 1);
    $run('2026-11-05 11:00:00', 'failed', 1, 0.5);
    $run('2026-11-02 11:00:00', 'finished', 3);

    $this->get(route('projects.view', ['shop', 'schedule', 'range' => '6h']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.0.runs', ['finished' => 1, 'failed' => 2, 'skipped' => 0])
            ->has('scheduleTimeline', 37)
            ->where('scheduleTimeline.27', ['at' => '2026-11-05T11:00:00Z', 'finished' => 0, 'failed' => 2, 'skipped' => 0])
            ->where('scheduleTimeline.33.finished', 1));

    $this->get(route('projects.view', ['shop', 'schedule', 'range' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->where('schedule.0.runs.finished', 4));
});

it('prunes exception occurrences past the exceptions retention', function () {
    app(StorePushedExceptionsAction::class)->execute($this->project, (string) Str::uuid(), [rangeExceptionGroup('a', 1, '2026-08-01T00:00:00Z')]);
    app(StorePushedExceptionsAction::class)->execute($this->project, (string) Str::uuid(), [rangeExceptionGroup('a', 1, '2026-11-05T00:00:00Z')]);

    app(PruneTelemetryAction::class)->execute(14, 30);

    expect(ExceptionOccurrence::query()->pluck('count')->all())->toBe([1])
        ->and(ExceptionGroup::query()->count())->toBe(1);
});

it('tells an exceptions tab without groups in the range from a project without exceptions', function () {
    $this->get(route('projects.view', ['shop', 'exceptions']))
        ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0)->where('hasExceptions', false));

    app(StorePushedExceptionsAction::class)->execute($this->project, (string) Str::uuid(), [rangeExceptionGroup('a', 1, '2026-11-01T00:00:00Z')]);

    $this->get(route('projects.view', ['shop', 'exceptions', 'range' => '24h']))
        ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0)->where('hasExceptions', true));
});
