<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertEventEnum;
use App\Models\Project;
use App\Services\AlertDispatcherService;
use App\Services\ProjectStalenessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Sends `project.stale` (#22) once per stale period: for each enabled project that has
 * reported before and is now past the stale window. The next report clears the mark and
 * sends `project.reporting_again`. Scheduled hourly.
 */
class AlertStaleProjectsCommand extends Command
{
    protected $signature = 'excubia:alert-stale';

    protected $description = 'Alert once for each project that stopped reporting';

    public function handle(ProjectStalenessService $staleness, AlertDispatcherService $alerts): int
    {
        $count = 0;

        Project::query()
            ->where('is_enabled', true)
            ->whereNotNull('last_report_at')
            ->whereNull('stale_alerted_at')
            ->each(function (Project $project) use ($staleness, $alerts, &$count): void {
                if (! $staleness->isStale($project)) {
                    return;
                }

                $alerts->dispatch(AlertEventEnum::ProjectStale, $project, 'no report since '.$project->last_report_at?->toDateTimeString(), [
                    'lastReportAt' => $project->last_report_at?->toIso8601ZuluString(),
                ]);
                $project->forceFill(['stale_alerted_at' => Date::now()])->save();
                $count++;
            });

        $this->components->info("Alerted {$count} stale projects.");

        return self::SUCCESS;
    }
}
