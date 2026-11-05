<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RunSystemReportAction;
use Illuminate\Console\Command;

/**
 * Excubia checks itself (#70): runs excubia-core on this install and stores the report on the
 * system project. Scheduled daily.
 */
class SystemReportCommand extends Command
{
    protected $signature = 'excubia:system-report';

    protected $description = 'Check this Excubia install and store the report on the system project';

    public function handle(RunSystemReportAction $runSystemReport): int
    {
        $report = $runSystemReport->execute();

        if ($report === null) {
            $this->components->warn('No enabled system project: nothing checked.');

            return self::SUCCESS;
        }

        $this->components->info('System report stored.');

        return self::SUCCESS;
    }
}
