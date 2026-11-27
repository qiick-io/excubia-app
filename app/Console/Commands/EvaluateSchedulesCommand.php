<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EvaluateHealthAlertsAction;
use App\Actions\EvaluateScheduledTasksAction;
use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\ScheduledTask;
use App\Services\HealthStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Judges the scheduled tasks of every enabled project with a schedule inventory (#110): late,
 * missed, failed or skipped runs, and alerts for missed and failed ones. Then the health alerts
 * of every enabled project with health samples in the window (#143): a server that went silent
 * sends no batch, so `health.stale` and the recovery that ignores it need this clock. Scheduled
 * every minute.
 */
class EvaluateSchedulesCommand extends Command
{
    protected $signature = 'excubia:evaluate-schedules';

    protected $description = 'Flag late, missed and failed scheduled tasks and stale health servers of the projects';

    public function handle(EvaluateScheduledTasksAction $evaluate, EvaluateHealthAlertsAction $evaluateHealth): int
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
        $count = 0;

        Project::query()
            ->where('is_enabled', true)
            ->whereIn('id', RuntimeAggregate::query()
                ->where('type', RuntimeTypeEnum::Health->value)
                ->where('bucket', '>=', Date::now()->subMinutes(HealthStatusService::WINDOW_MINUTES))
                ->select('project_id'))
            ->each(function (Project $project) use ($evaluateHealth, &$count): void {
                $evaluateHealth->execute($project);
                $count++;
            });

        $this->components->info("Evaluated the health of {$count} projects.");

        return self::SUCCESS;
    }
}
