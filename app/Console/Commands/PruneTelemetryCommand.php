<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PruneTelemetryAction;
use Illuminate\Console\Command;

/**
 * Bounds runtime aggregates and exception groups to `excubia.runtime_retention_days` and
 * `excubia.exceptions_retention_days`. Scheduled daily.
 */
class PruneTelemetryCommand extends Command
{
    protected $signature = 'excubia:prune-telemetry';

    protected $description = 'Delete runtime aggregates and exception groups past their retention';

    public function handle(PruneTelemetryAction $pruneTelemetry): int
    {
        $deleted = $pruneTelemetry->execute((int) config('excubia.runtime_retention_days'), (int) config('excubia.exceptions_retention_days'));

        $this->components->info("Deleted {$deleted['runtime']} runtime aggregates and {$deleted['exceptions']} exception groups.");

        return self::SUCCESS;
    }
}
