<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EvaluateScheduledTasksAction;
use App\Models\Project;
use App\Models\ScheduledTask;
use Illuminate\Console\Command;

/**
 * Judges the scheduled tasks of every enabled project with a schedule inventory (#110): late,
 * missed, failed or skipped runs, and alerts for missed and failed ones. Scheduled every minute.
 */
class EvaluateSchedulesCommand extends Command
{
    protected $signature = 'excubia:evaluate-schedules';

    protected $description = 'Flag late, missed and failed scheduled tasks of the projects';

    public function handle(EvaluateScheduledTasksAction $evaluate): int
    {
        $count = 0;

        Project::query()
            ->where('is_enabled', true)
            ->whereIn('id', ScheduledTask::query()->select('project_id'))
            ->each(function (Project $project) use ($evaluate, &$count): void {
                $evaluate->execute($project);
                $count++;
            });

        $this->components->info("Evaluated the schedules of {$count} projects.");

        return self::SUCCESS;
    }
}
