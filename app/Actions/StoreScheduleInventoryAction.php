<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\ScheduledTask;

/**
 * Replaces a project's schedule inventory (#110) with the `schedule` of a report push, keyed by
 * `taskId`: a task that left the schedule is no longer expected, a task that stays keeps its
 * status and `created_at` (first seen). Called inside the report transaction.
 */
class StoreScheduleInventoryAction
{
    /**
     * @param  array<mixed>  $schedule  the report's `schedule`, validated by StorePushedReportRequest
     */
    public function execute(Project $project, array $schedule): void
    {
        $taskIds = [];

        foreach (array_values(array_filter($schedule, is_array(...))) as $position => $entry) {
            $taskId = (string) $entry['taskId'];
            $taskIds[] = $taskId;

            ScheduledTask::query()->updateOrCreate(['project_id' => $project->id, 'task_id' => $taskId], [
                'task' => mb_substr((string) $entry['task'], 0, 255),
                'expression' => (string) $entry['expression'],
                'timezone' => (string) $entry['timezone'],
                'without_overlapping' => (bool) $entry['withoutOverlapping'],
                'on_one_server' => (bool) $entry['onOneServer'],
                'runs_in_maintenance_mode' => (bool) $entry['runsInMaintenanceMode'],
                'environments' => array_values(array_map(strval(...), (array) $entry['environments'])),
                'late_after_minutes' => min((int) ($entry['lateAfterMinutes'] ?? 5), 65535),
                'position' => $position,
            ]);
        }

        ScheduledTask::query()->where('project_id', $project->id)->whereNotIn('task_id', $taskIds)->delete();
    }
}
