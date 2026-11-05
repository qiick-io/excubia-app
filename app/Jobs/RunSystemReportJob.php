<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RunSystemReportAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Refresh now" on the system project (#70). The checks call Packagist, OSV and endoflife.date,
 * so they run on the queue (inline with the `sync` connection); one at a time.
 */
class RunSystemReportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function handle(RunSystemReportAction $runSystemReport): void
    {
        $runSystemReport->execute();
    }
}
