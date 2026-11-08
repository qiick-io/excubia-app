<?php

declare(strict_types=1);

use App\Data\ScheduleContextData;
use App\Data\ScheduledTaskEvaluationData;
use App\Enums\ScheduledTaskStatusEnum;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\ScheduledTaskEvaluationService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Due times and classification of scheduled tasks (#110): timezones, DST, sub-minute
 * expressions, the grace and push delay, environments, maintenance mode and sampling.
 */
uses(TestCase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function scheduledTask(string $expression, array $attributes = []): ScheduledTask
{
    $task = new ScheduledTask([
        'task_id' => str_repeat('a', 64),
        'task' => 'artisan reports:send',
        'expression' => $expression,
        'timezone' => 'UTC',
        'environments' => [],
        'can_run_in_maintenance_mode' => false,
        'late_after_minutes' => 5,
        ...$attributes,
    ]);
    $task->created_at = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');

    return $task;
}

/**
 * @param  array<string, string>  $runs  UTC minute => outcome
 */
function evaluateTask(ScheduledTask $task, string $now, array $runs = [], array $context = []): ScheduledTaskEvaluationData
{
    $models = [];

    foreach ($runs as $bucket => $outcome) {
        $models[] = new ScheduledTaskRun(['bucket' => CarbonImmutable::parse($bucket, 'UTC'), 'outcome' => $outcome, 'count' => 1, 'sample_rate' => 1]);
    }

    return (new ScheduledTaskEvaluationService)->evaluate($task, new ScheduleContextData(
        now: CarbonImmutable::parse($now, 'UTC'),
        environment: array_key_exists('environment', $context) ? $context['environment'] : 'production',
        isInMaintenanceMode: $context['isInMaintenanceMode'] ?? false,
        sampleRate: $context['sampleRate'] ?? 1.0,
        runsSince: array_key_exists('runsSince', $context) ? $context['runsSince'] : CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
    ), $models);
}

it('is on time when the task ran within the grace of its last due time', function () {
    $result = evaluateTask(scheduledTask('0 3 * * *'), '2026-11-05 12:00:00', ['2026-11-05 03:01:00' => 'finished']);

    expect($result->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and($result->dueAt?->toDateTimeString())->toBe('2026-11-05 03:00:00')
        ->and($result->nextDueAt?->toDateTimeString())->toBe('2026-11-06 03:00:00')
        ->and($result->isLowConfidence)->toBeFalse();
});

it('is late without a run past the grace and the push delay, and missed once the next due time came', function () {
    $task = scheduledTask('0 * * * *');
    $runs = ['2026-11-05 10:00:00' => 'finished'];

    // Due 11:00; the grace (5) plus the push delay (2) end at 11:07. Until then 10:00 counts.
    expect(evaluateTask($task, '2026-11-05 11:06:00', $runs)->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and(evaluateTask($task, '2026-11-05 11:07:00', $runs)->status)->toBe(ScheduledTaskStatusEnum::Late)
        ->and(evaluateTask($task, '2026-11-05 12:02:00', $runs)->status)->toBe(ScheduledTaskStatusEnum::Missed)
        ->and(evaluateTask($task, '2026-11-05 12:02:00', $runs)->dueAt?->toDateTimeString())->toBe('2026-11-05 11:00:00');
});

it('keeps the previous due time while the latest one is still within its grace', function () {
    $result = evaluateTask(scheduledTask('0 * * * *'), '2026-11-05 12:04:00', ['2026-11-05 11:00:00' => 'finished']);

    expect($result->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and($result->dueAt?->toDateTimeString())->toBe('2026-11-05 11:00:00');
});

it('is late when the only run came after the grace', function () {
    expect(evaluateTask(scheduledTask('0 * * * *'), '2026-11-05 11:30:00', ['2026-11-05 11:20:00' => 'finished'])->status)
        ->toBe(ScheduledTaskStatusEnum::Late);
});

it('is failed on a failed run and skipped on skipped runs only', function () {
    $task = scheduledTask('*/10 * * * *');

    expect(evaluateTask($task, '2026-11-05 12:05:00', ['2026-11-05 12:00:00' => 'failed'])->status)->toBe(ScheduledTaskStatusEnum::Failed)
        ->and(evaluateTask($task, '2026-11-05 12:05:00', ['2026-11-05 12:00:00' => 'skipped'])->status)->toBe(ScheduledTaskStatusEnum::Skipped);
});

it('reads the expression in the task timezone', function () {
    // 03:00 in Rome is 02:00 UTC in November.
    $task = scheduledTask('0 3 * * *', ['timezone' => 'Europe/Rome']);
    $result = evaluateTask($task, '2026-11-05 12:00:00', ['2026-11-05 02:00:00' => 'finished']);

    expect($result->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and($result->dueAt?->toDateTimeString())->toBe('2026-11-05 02:00:00')
        ->and(evaluateTask($task, '2026-11-05 12:00:00', ['2026-11-05 03:00:00' => 'finished'])->status)->toBe(ScheduledTaskStatusEnum::Late);
});

it('follows DST as the Laravel scheduler does: a skipped time is not due, a repeated one is due twice', function () {
    $task = scheduledTask('30 2 * * *', ['timezone' => 'Europe/Rome']);

    // 2026-03-29: 02:30 does not exist in Rome, so the last due time is the day before (01:30 UTC).
    $spring = evaluateTask($task, '2026-03-29 12:00:00', ['2026-03-28 01:30:00' => 'finished']);
    expect($spring->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and($spring->dueAt?->toDateTimeString())->toBe('2026-03-28 01:30:00')
        ->and($spring->nextDueAt?->toDateTimeString())->toBe('2026-03-30 00:30:00');

    // 2026-10-25: 02:30 happens twice (00:30 and 01:30 UTC); the second one needs its own run.
    expect(evaluateTask($task, '2026-10-25 12:00:00', ['2026-10-25 00:30:00' => 'finished', '2026-10-25 01:30:00' => 'finished'])->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and(evaluateTask($task, '2026-10-25 12:00:00', ['2026-10-25 00:30:00' => 'finished'])->dueAt?->toDateTimeString())->toBe('2026-10-25 01:30:00');
});

it('reads a six-field expression with seconds per minute', function () {
    $task = scheduledTask('*/10 * * * * *');

    expect(evaluateTask($task, '2026-11-05 12:00:00', ['2026-11-05 11:58:00' => 'finished'])->status)->toBe(ScheduledTaskStatusEnum::OnTime)
        ->and(evaluateTask($task, '2026-11-05 12:00:00', ['2026-11-05 11:40:00' => 'finished'])->status)->toBe(ScheduledTaskStatusEnum::Missed)
        ->and(evaluateTask($task, '2026-11-05 12:00:00')->nextDueAt?->toDateTimeString())->toBe('2026-11-05 12:01:00');
});

it('expects no run in another environment, and is low confidence when the environment is unknown', function () {
    $task = scheduledTask('0 * * * *', ['environments' => ['production']]);

    expect(evaluateTask($task, '2026-11-05 12:30:00', context: ['environment' => 'staging'])->status)->toBe(ScheduledTaskStatusEnum::NotExpected)
        ->and(evaluateTask($task, '2026-11-05 12:30:00', context: ['environment' => 'production'])->isLowConfidence)->toBeFalse();

    $unknown = evaluateTask($task, '2026-11-05 12:30:00', context: ['environment' => null]);
    expect($unknown->status)->toBe(ScheduledTaskStatusEnum::Late)
        ->and($unknown->isLowConfidence)->toBeTrue();
});

it('pauses in maintenance mode unless the task runs then', function () {
    expect(evaluateTask(scheduledTask('0 * * * *'), '2026-11-05 12:30:00', context: ['isInMaintenanceMode' => true])->status)->toBe(ScheduledTaskStatusEnum::Paused)
        ->and(evaluateTask(scheduledTask('0 * * * *', ['can_run_in_maintenance_mode' => true]), '2026-11-05 12:30:00', context: ['isInMaintenanceMode' => true])->status)->toBe(ScheduledTaskStatusEnum::Late);
});

it('marks a missing run low confidence when runs are sampled, never a seen failure', function () {
    $task = scheduledTask('0 * * * *');

    expect(evaluateTask($task, '2026-11-05 12:30:00', context: ['sampleRate' => 0.5])->isLowConfidence)->toBeTrue()
        ->and(evaluateTask($task, '2026-11-05 12:30:00', ['2026-11-05 12:00:00' => 'failed'], ['sampleRate' => 0.5])->isLowConfidence)->toBeFalse();
});

it('judges no due time before the app had run data or the task appeared', function () {
    $task = scheduledTask('0 3 * * *');

    expect(evaluateTask($task, '2026-11-05 12:00:00', context: ['runsSince' => null])->status)->toBe(ScheduledTaskStatusEnum::Unknown)
        ->and(evaluateTask($task, '2026-11-05 12:00:00', context: ['runsSince' => CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC')])->status)->toBe(ScheduledTaskStatusEnum::Pending);

    $task->created_at = CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC');
    expect(evaluateTask($task, '2026-11-05 12:00:00')->status)->toBe(ScheduledTaskStatusEnum::Pending);
});

it('is unknown for an expression or timezone it cannot read', function () {
    expect(evaluateTask(scheduledTask('not a cron'), '2026-11-05 12:00:00')->status)->toBe(ScheduledTaskStatusEnum::Unknown)
        ->and(evaluateTask(scheduledTask('* * * * *', ['timezone' => 'Mars/Olympus']), '2026-11-05 12:00:00')->status)->toBe(ScheduledTaskStatusEnum::Unknown);
});
