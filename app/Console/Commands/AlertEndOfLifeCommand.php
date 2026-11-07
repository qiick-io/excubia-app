<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\EolAlertsService;
use Illuminate\Console\Command;

/**
 * Sends the end-of-life alerts (#157) that the calendar made due since the last report: each
 * enabled project's latest report is evaluated again. Scheduled daily; a report push evaluates
 * its own project at once.
 */
class AlertEndOfLifeCommand extends Command
{
    protected $signature = 'excubia:alert-eol';

    protected $description = 'Alert when PHP, Laravel or Node security support of a project ends soon or has ended';

    public function handle(EolAlertsService $eol): int
    {
        $count = 0;

        Project::query()
            ->where('is_enabled', true)
            ->whereNotNull('last_report_at')
            ->with('latestReport')
            ->each(function (Project $project) use ($eol, &$count): void {
                $count += $eol->evaluate($project);
            });

        $this->components->info("Raised {$count} end-of-life alerts.");

        return self::SUCCESS;
    }
}
