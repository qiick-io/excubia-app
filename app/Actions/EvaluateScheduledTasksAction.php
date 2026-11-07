<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ScheduleContextData;
use App\Data\ScheduledTaskEvaluationData;
use App\Enums\AlertEventEnum;
use App\Enums\ScheduledTaskStatusEnum;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\AlertDispatcherService;
use App\Services\ReportFindingsService;
use App\Services\ScheduledTaskEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Evaluates the schedule of one project (#110) and stores each task's status, due times and last
 * run. When `excubia:heartbeat` (every minute) is late or missed too, the other tasks that are
 * late or missed are `scheduler_down`: one `schedule.scheduler_down` alert instead of one per
 * task. Alerts (#22): `schedule.missed` and `schedule.failed` once per problem until the task is
 * fine again; never for a low-confidence status (sampled runs, unknown environment).
 *
 * ponytail: two queries per task per run (its runs since the oldest due time judged, and its last
 * run); one query per project if schedules grow to hundreds of tasks.
 */
class EvaluateScheduledTasksAction
{
    private const MAINTENANCE_CHECK = 'laravel-maintenance-mode';

    public function __construct(
        private ScheduledTaskEvaluationService $evaluation,
        private ReportFindingsService $findings,
        private AlertDispatcherService $alerts,
    ) {}

    public function execute(Project $project): void
    {
        $tasks = ScheduledTask::query()->where('project_id', $project->id)->orderBy('position')->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $context = $this->context($project);

        /** @var array<int, ScheduledTaskEvaluationData> $results */
        $results = [];

        foreach ($tasks as $task) {
            $since = $this->evaluation->earliestDueTime($task, $context);
            $runs = $since === null ? collect() : ScheduledTaskRun::query()
                ->where('project_id', $project->id)
                ->where('task_id', $task->task_id)
                ->where('bucket', '>=', $since)
                ->get();
            $results[$task->id] = $this->evaluation->evaluate($task, $context, $runs);
        }

        $heartbeat = $tasks->firstWhere('task', ScheduledTask::HEARTBEAT_TASK);
        $isSchedulerDown = $heartbeat !== null && $results[$heartbeat->id]->status->isMissingRun();

        foreach ($tasks as $task) {
            $result = $results[$task->id];

            if ($isSchedulerDown && $task->isNot($heartbeat) && $result->status->isMissingRun()) {
                $result = new ScheduledTaskEvaluationData(ScheduledTaskStatusEnum::SchedulerDown, $result->dueAt, $result->nextDueAt, $result->isLowConfidence);
            }

            $this->store($project, $task, $result, $task->is($heartbeat));
        }
    }

    private function context(Project $project): ScheduleContextData
    {
        $runs = ScheduledTaskRun::query()->where('project_id', $project->id);
        $oldest = (clone $runs)->min('bucket');
        $report = $project->healthReports()->latest('received_at')->latest('id')->first();
        $isInMaintenanceMode = $report !== null && collect($this->findings->issues($report))->contains('checkId', self::MAINTENANCE_CHECK);

        return new ScheduleContextData(
            now: CarbonImmutable::instance(Date::now()),
            environment: $project->exceptionGroups()->orderByDesc('last_seen')->value('environment'),
            isInMaintenanceMode: $isInMaintenanceMode,
            sampleRate: ($rate = (clone $runs)->orderByDesc('bucket')->value('sample_rate')) === null ? null : (float) $rate,
            runsSince: $oldest === null ? null : CarbonImmutable::parse((string) $oldest, 'UTC'),
        );
    }

    /**
     * Stores the result, then alerts when the task enters `missed` or `failed` (the heartbeat:
     * `schedule.scheduler_down`) with confidence and that problem was not alerted yet.
     */
    private function store(Project $project, ScheduledTask $task, ScheduledTaskEvaluationData $result, bool $isHeartbeat): void
    {
        $lastRun = ScheduledTaskRun::query()
            ->where('project_id', $project->id)
            ->where('task_id', $task->task_id)
            ->orderByDesc('bucket')
            ->orderByRaw("case outcome when 'failed' then 0 when 'finished' then 1 else 2 end")
            ->first();
        $now = Date::now();

        $event = match (true) {
            $result->isLowConfidence => null,
            $isHeartbeat && $result->status === ScheduledTaskStatusEnum::Missed => AlertEventEnum::SchedulerDown,
            $result->status === ScheduledTaskStatusEnum::Missed => AlertEventEnum::ScheduleMissed,
            $result->status === ScheduledTaskStatusEnum::Failed => AlertEventEnum::ScheduleFailed,
            default => null,
        };

        if ($event !== null && $task->alerted_status !== $result->status) {
            $this->alerts->dispatch($event, $project, "{$task->task} ({$task->expression}): {$result->status->value}", [
                'taskId' => $task->task_id,
                'task' => $task->task,
                'expression' => $task->expression,
                'timezone' => $task->timezone,
                'dueAt' => $result->dueAt?->toIso8601ZuluString(),
            ]);
            $task->alerted_status = $result->status;
        } elseif ($result->status->isHealthy()) {
            $task->alerted_status = null;
        }

        $task->forceFill([
            'status_changed_at' => $task->status === $result->status ? $task->status_changed_at : $now,
            'status' => $result->status,
            'is_low_confidence' => $result->isLowConfidence,
            'last_due_at' => $result->dueAt,
            'next_due_at' => $result->nextDueAt,
            'last_run_at' => $lastRun?->bucket,
            'last_outcome' => $lastRun?->outcome,
            'evaluated_at' => $now,
        ])->save();
    }
}
