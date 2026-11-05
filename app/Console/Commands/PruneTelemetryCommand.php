<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PruneTelemetryAction;
use App\Services\SettingsRepositoryService;
use Illuminate\Console\Command;

/**
 * Bounds runtime aggregates and exception groups to the Data retention settings (defaults
 * `excubia.runtime_retention_days` and `excubia.exceptions_retention_days`). Scheduled daily.
 */
class PruneTelemetryCommand extends Command
{
    protected $signature = 'excubia:prune-telemetry';

    protected $description = 'Delete runtime aggregates and exception groups past their retention';

    public function handle(PruneTelemetryAction $pruneTelemetry, SettingsRepositoryService $settings): int
    {
        $deleted = $pruneTelemetry->execute($settings->runtimeRetentionDays(), $settings->exceptionsRetentionDays());

        $this->components->info("Deleted {$deleted['runtime']} runtime aggregates and {$deleted['exceptions']} exception groups.");

        return self::SUCCESS;
    }
}
