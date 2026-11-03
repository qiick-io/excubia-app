<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PruneHealthReportsAction;
use App\Services\SettingsRepositoryService;
use Illuminate\Console\Command;

/**
 * Bounds the report history: keeps the newest reports of each project, as many as the
 * "Reports kept per project" setting (default `excubia.reports_kept_per_project`). Scheduled daily.
 */
class PruneReportsCommand extends Command
{
    protected $signature = 'excubia:prune-reports';

    protected $description = 'Delete the oldest reports beyond the number kept per project';

    public function handle(PruneHealthReportsAction $pruneReports, SettingsRepositoryService $settings): int
    {
        $keep = $settings->reportsKeptPerProject();
        $deleted = $pruneReports->execute($keep);

        $this->components->info("Deleted {$deleted} reports, keeping the newest {$keep} per project.");

        return self::SUCCESS;
    }
}
