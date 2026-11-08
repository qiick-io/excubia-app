<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScheduledTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A scheduled task for the project's Schedule tab (#110): its name, expression and options, its
 * last run, next due time and status (as of `evaluated_at`). Times are ISO 8601 UTC.
 *
 * @mixin ScheduledTask
 */
class ScheduledTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ScheduledTask $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'task' => $task->task,
            'expression' => $task->expression,
            'timezone' => $task->timezone,
            'environments' => $task->environments,
            'without_overlapping' => $task->without_overlapping,
            'on_one_server' => $task->on_one_server,
            'runs_in_maintenance_mode' => $task->runs_in_maintenance_mode,
            'late_after_minutes' => $task->late_after_minutes,
            'is_heartbeat' => $task->task === ScheduledTask::HEARTBEAT_TASK,
            'status' => $task->status->value,
            'is_low_confidence' => $task->is_low_confidence,
            'last_due_at' => $task->last_due_at?->toIso8601String(),
            'next_due_at' => $task->next_due_at?->toIso8601String(),
            'last_run_at' => $task->last_run_at?->toIso8601String(),
            'last_outcome' => $task->last_outcome,
            'status_changed_at' => $task->status_changed_at?->toIso8601String(),
            'evaluated_at' => $task->evaluated_at?->toIso8601String(),
        ];
    }
}
