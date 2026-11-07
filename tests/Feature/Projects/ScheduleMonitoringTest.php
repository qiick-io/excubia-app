<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Schedule monitoring (#110): the inventory from report pushes, runs from runtime pushes by
 * `taskId`, `excubia:evaluate-schedules` (on time, late, missed, failed, skipped, maintenance,
 * environments, scheduler down, sampling), its alerts, and the Schedule tab.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Date::setTestNow('2026-11-05 08:00:00');
    app(SettingsRepositoryService::class)->save([
        'webhook_url' => 'https://hooks.example.com/excubia',
        'webhook_secret' => str_repeat('s', 32),
    ]);
});

function scheduleTaskId(string $task, string $expression): string
{
    return hash('sha256', $task."\0".$expression);
}

/**
 * A schedule inventory entry as excubia-core sends it.
 *
 * @return array<string, mixed>
 */
function scheduleEntry(string $task, string $expression, array $overrides = []): array
{
    return [
        'taskId' => scheduleTaskId($task, $expression),
        'task' => $task,
        'expression' => $expression,
        'timezone' => 'UTC',
        'withoutOverlapping' => false,
        'onOneServer' => false,
        'runsInMaintenanceMode' => false,
        'environments' => [],
        'lateAfterMinutes' => 5,
        ...$overrides,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function defaultSchedule(): array
{
    return [
        scheduleEntry(ScheduledTask::HEARTBEAT_TASK, '* * * * *'),
        scheduleEntry('artisan reports:send', '0 * * * *'),
    ];
}

/**
 * Pushes a report with the given schedule (none when null) and, optionally, the maintenance finding.
 *
 * @param  list<array<string, mixed>>|null  $schedule
 */
function pushSchedule(Project $project, string $token, ?array $schedule, bool $isInMaintenanceMode = false): mixed
{
    $report = [
        'schemaVersion' => 1,
        'checks' => [[
            'checkId' => 'laravel-maintenance-mode',
            'name' => 'Maintenance mode',
            'status' => $isInMaintenanceMode ? 'warn' : 'pass',
            'findings' => $isInMaintenanceMode ? [['checkId' => 'laravel-maintenance-mode', 'severity' => 'medium', 'status' => 'warn', 'subject' => 'maintenance-mode', 'message' => 'The application is in maintenance mode.', 'fixHint' => 'Run php artisan up.', 'evidenceUrl' => null]] : [],
        ]],
        'upgradePlan' => [],
    ];

    if ($schedule !== null) {
        $report['schedule'] = $schedule;
    }

    return test()->withToken($token)->postJson('/api/reports', [
        'protocolVersion' => 1,
        'batchId' => (string) Str::uuid(),
        'sentAt' => Date::now()->toIso8601ZuluString(),
        'report' => $report,
    ]);
}

/**
 * Pushes `scheduledTask` runtime aggregates: [task, expression, UTC minute, outcome, sampleRate?].
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: float}>  $runs
 */
function pushRuns(string $token, array $runs, bool $hasTaskId = true): mixed
{
    $aggregates = array_map(fn (array $run): array => array_filter([
        'type' => 'scheduledTask',
        'bucket' => Date::parse($run[2])->utc()->format('Y-m-d\TH:i:00\Z'),
        'sampleRate' => $run[4] ?? 1,
        'task' => $run[0],
        'taskId' => $hasTaskId ? scheduleTaskId($run[0], $run[1]) : null,
        'outcome' => $run[3],
        'count' => 1,
        'errorCount' => $run[3] === 'failed' ? 1 : 0,
        'totalMs' => 100,
        'maxMs' => 100,
    ], fn (mixed $value): bool => $value !== null), $runs);

    return test()->withToken($token)->postJson('/api/runtime', [
        'protocolVersion' => 1,
        'type' => 'runtime',
        'batchId' => (string) Str::uuid(),
        'sentAt' => Date::now()->toIso8601ZuluString(),
        'runtime' => ['bucketSeconds' => 60, 'aggregates' => $aggregates],
    ]);
}

/**
 * Heartbeat runs for every minute from `$from` to `$until` (UTC, inclusive).
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function heartbeats(string $from, string $until, float $sampleRate = 1): array
{
    $runs = [];

    for ($minute = Date::parse($from); $minute->lte(Date::parse($until)); $minute = $minute->addMinute()) {
        $runs[] = [ScheduledTask::HEARTBEAT_TASK, '* * * * *', $minute->toDateTimeString(), 'finished', $sampleRate];
    }

    return $runs;
}

/**
 * A project that pushed the default schedule at 08:00 and runs since then (heartbeat each minute
 * until `$heartbeatUntil`, plus `$runs`), evaluated at `$now`.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: float}>  $runs
 * @return array{0: Project, 1: string}
 */
function scheduledProject(string $now, array $runs = [], ?string $heartbeatUntil = null, float $sampleRate = 1, ?array $schedule = null, bool $isInMaintenanceMode = false): array
{
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $token = app(RotateProjectTokenAction::class)->execute($project);
    pushSchedule($project, $token, $schedule ?? defaultSchedule(), $isInMaintenanceMode)->assertCreated();
    pushRuns($token, [...heartbeats('2026-11-05 08:00:00', $heartbeatUntil ?? Date::parse($now)->subMinutes(2)->toDateTimeString(), $sampleRate), ...$runs])->assertCreated();

    Date::setTestNow($now);
    test()->artisan('excubia:evaluate-schedules')->assertSuccessful();

    return [$project, $token];
}

function taskStatus(Project $project, string $task): string
{
    return ScheduledTask::query()->where('project_id', $project->id)->where('task', $task)->firstOrFail()->status->value;
}

/**
 * @return list<string> the alert events queued for the webhook
 */
function scheduleAlerts(): array
{
    return collect(Queue::pushedJobs()[DeliverAlertJob::class] ?? [])
        ->map(fn (array $pushed): string => $pushed['job']->payload['event'])
        ->filter(fn (string $event): bool => str_starts_with($event, 'schedule.'))
        ->values()
        ->all();
}

it('stores the schedule inventory, replaces it on each push and keeps it when a push has none', function () {
    $project = Project::factory()->create();
    $token = app(RotateProjectTokenAction::class)->execute($project);

    pushSchedule($project, $token, [...defaultSchedule(), scheduleEntry('Closure', '0 3 * * *', ['timezone' => 'Europe/Rome', 'environments' => ['production'], 'onOneServer' => true])])->assertCreated();

    $tasks = $project->scheduledTasks()->get();
    expect($tasks->pluck('task')->all())->toBe([ScheduledTask::HEARTBEAT_TASK, 'artisan reports:send', 'Closure'])
        ->and($tasks[2]->timezone)->toBe('Europe/Rome')
        ->and($tasks[2]->environments)->toBe(['production'])
        ->and($tasks[2]->on_one_server)->toBeTrue()
        ->and($tasks[2]->late_after_minutes)->toBe(5);

    pushSchedule($project, $token, defaultSchedule())->assertCreated();
    expect($project->scheduledTasks()->count())->toBe(2);

    pushSchedule($project, $token, null)->assertCreated();
    expect($project->scheduledTasks()->count())->toBe(2);
});

it('rejects an invalid schedule inventory', function () {
    $project = Project::factory()->create();
    $token = app(RotateProjectTokenAction::class)->execute($project);

    pushSchedule($project, $token, [['taskId' => 'nope', 'task' => 'x']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['report.schedule.0.taskId', 'report.schedule.0.expression', 'report.schedule.0.environments']);
});

it('stores runs by taskId, bucket and outcome, additive, and ignores aggregates without a taskId', function () {
    $project = Project::factory()->create();
    $token = app(RotateProjectTokenAction::class)->execute($project);
    $run = ['artisan reports:send', '0 * * * *', '2026-11-05 07:00:00', 'finished'];

    pushRuns($token, [$run])->assertCreated();
    pushRuns($token, [[...$run, 4 => 0.5]])->assertCreated();
    pushRuns($token, [['artisan legacy', '0 * * * *', '2026-11-05 07:00:00', 'finished']], hasTaskId: false)->assertCreated();

    $stored = ScheduledTaskRun::query()->where('project_id', $project->id)->sole();
    expect($stored->task_id)->toBe(scheduleTaskId('artisan reports:send', '0 * * * *'))
        ->and($stored->count)->toBe(2)
        ->and($stored->sample_rate)->toBe(0.5)
        ->and($stored->outcome)->toBe('finished');
});

it('is on time when the task ran, without alerts', function () {
    [$project] = scheduledProject('2026-11-05 09:10:00', [['artisan reports:send', '0 * * * *', '2026-11-05 09:01:00', 'finished']]);

    $task = ScheduledTask::query()->where('task', 'artisan reports:send')->sole();
    expect($task->status->value)->toBe('on_time')
        ->and($task->last_run_at?->toDateTimeString())->toBe('2026-11-05 09:01:00')
        ->and($task->last_outcome)->toBe('finished')
        ->and($task->next_due_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and(taskStatus($project, ScheduledTask::HEARTBEAT_TASK))->toBe('on_time')
        ->and(scheduleAlerts())->toBe([]);
});

it('is late past the grace without alerting, then missed with one alert', function () {
    [$project] = scheduledProject('2026-11-05 09:10:00');

    expect(taskStatus($project, 'artisan reports:send'))->toBe('late')
        ->and(scheduleAlerts())->toBe([]);

    pushRuns(app(RotateProjectTokenAction::class)->execute($project), heartbeats('2026-11-05 09:09:00', '2026-11-05 10:05:00'))->assertCreated();
    Date::setTestNow('2026-11-05 10:03:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();
    Date::setTestNow('2026-11-05 10:04:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();

    expect(taskStatus($project, 'artisan reports:send'))->toBe('missed')
        ->and(scheduleAlerts())->toBe(['schedule.missed']);
});

it('alerts once for a failed run', function () {
    [$project] = scheduledProject('2026-11-05 09:10:00', [['artisan reports:send', '0 * * * *', '2026-11-05 09:00:00', 'failed']]);
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();

    expect(taskStatus($project, 'artisan reports:send'))->toBe('failed')
        ->and(scheduleAlerts())->toBe(['schedule.failed']);
});

it('reports skipped runs without alerting', function () {
    [$project] = scheduledProject('2026-11-05 09:10:00', [['artisan reports:send', '0 * * * *', '2026-11-05 09:00:00', 'skipped']]);

    expect(taskStatus($project, 'artisan reports:send'))->toBe('skipped')
        ->and(scheduleAlerts())->toBe([]);
});

it('expects no runs in maintenance mode unless the task runs then', function () {
    $schedule = [...defaultSchedule(), scheduleEntry('artisan backup:run', '0 * * * *', ['runsInMaintenanceMode' => true])];
    [$project] = scheduledProject('2026-11-05 10:05:00', schedule: $schedule, isInMaintenanceMode: true);

    expect(taskStatus($project, 'artisan reports:send'))->toBe('paused')
        ->and(taskStatus($project, 'artisan backup:run'))->toBe('missed')
        ->and(scheduleAlerts())->toBe(['schedule.missed']);
});

it('expects no runs of a task limited to another environment', function () {
    $schedule = [...defaultSchedule(), scheduleEntry('artisan billing:charge', '0 * * * *', ['environments' => ['production']])];
    $project = Project::factory()->create();
    ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat('a', 64), 'environment' => 'staging', 'class' => 'RuntimeException',
        'message' => 'x', 'frames' => [], 'previous' => [], 'context_kind' => 'request', 'context_name' => 'home', 'count' => 1,
        'first_seen' => Date::now(), 'last_seen' => Date::now(), 'status' => 'open',
    ]);
    $token = app(RotateProjectTokenAction::class)->execute($project);
    pushSchedule($project, $token, $schedule)->assertCreated();
    pushRuns($token, heartbeats('2026-11-05 08:00:00', '2026-11-05 10:03:00'))->assertCreated();
    Date::setTestNow('2026-11-05 10:05:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();

    expect(taskStatus($project, 'artisan billing:charge'))->toBe('not_expected')
        ->and(taskStatus($project, 'artisan reports:send'))->toBe('missed');
});

it('tells the whole scheduler down from one missed task, with one alert', function () {
    // The heartbeat stopped at 08:30: no task ran since.
    [$project] = scheduledProject('2026-11-05 10:05:00', heartbeatUntil: '2026-11-05 08:30:00');

    expect(taskStatus($project, ScheduledTask::HEARTBEAT_TASK))->toBe('missed')
        ->and(taskStatus($project, 'artisan reports:send'))->toBe('scheduler_down')
        ->and(scheduleAlerts())->toBe(['schedule.scheduler_down']);
});

it('does not alert a missing run when runs are sampled', function () {
    [$project] = scheduledProject('2026-11-05 10:05:00', sampleRate: 0.5);

    $task = ScheduledTask::query()->where('task', 'artisan reports:send')->sole();
    expect($task->status->value)->toBe('missed')
        ->and($task->is_low_confidence)->toBeTrue()
        ->and(scheduleAlerts())->toBe([]);
});

it('stays unknown until runs with a taskId reach the app', function () {
    $project = Project::factory()->create();
    pushSchedule($project, app(RotateProjectTokenAction::class)->execute($project), defaultSchedule())->assertCreated();
    Date::setTestNow('2026-11-05 10:05:00');
    $this->artisan('excubia:evaluate-schedules')->assertSuccessful();

    expect(taskStatus($project, 'artisan reports:send'))->toBe('unknown')
        ->and(scheduleAlerts())->toBe([]);
});

it('shows each task with its last run, next due time and status on the Schedule tab', function () {
    $this->seed(PermissionSeeder::class);
    scheduledProject('2026-11-05 09:10:00', [['artisan reports:send', '0 * * * *', '2026-11-05 09:01:00', 'finished']]);

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))
        ->get(route('projects.view', ['shop', 'schedule']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('view', 'schedule')
            ->has('schedule', 2)
            ->where('schedule.0.is_heartbeat', true)
            ->where('schedule.1.task', 'artisan reports:send')
            ->where('schedule.1.expression', '0 * * * *')
            ->where('schedule.1.status', 'on_time')
            ->where('schedule.1.last_run_at', '2026-11-05T09:01:00+00:00')
            ->where('schedule.1.next_due_at', '2026-11-05T10:00:00+00:00')
            ->missing('schedule.1.task_id'));

    $this->get(route('projects.show', 'shop'))->assertInertia(fn (Assert $page) => $page->where('schedule', []));
});

it('prunes runs past the runtime retention', function () {
    $project = Project::factory()->create();
    ScheduledTaskRun::query()->create(['project_id' => $project->id, 'task_id' => str_repeat('a', 64), 'bucket' => Date::now()->subDays(30), 'outcome' => 'finished', 'count' => 1, 'sample_rate' => 1]);

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect(ScheduledTaskRun::query()->count())->toBe(0);
});
