<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PruneTelemetryAction;
use App\Services\SettingsRepositoryService;
use Illuminate\Console\Command;

/**
 * Bounds runtime aggregates and exception groups to the Data retention settings (defaults
 * `excubia.runtime_retention_days` and `excubia.exceptions_retention_days`), and exception
 * occurrences to `excubia.exception_occurrence_days` (#214, at least the 14 d range). Scheduled
 * daily.
 */
class PruneTelemetryCommand extends Command
{
    protected $signature = 'excubia:prune-telemetry';

    protected $description = 'Delete runtime aggregates, exception groups and in-app notifications past their retention';

    public function handle(PruneTelemetryAction $pruneTelemetry, SettingsRepositoryService $settings): int
    {
        $occurrenceDays = max(PruneTelemetryAction::OCCURRENCE_DAYS, (int) config('excubia.exception_occurrence_days'));
        $deleted = $pruneTelemetry->execute($settings->runtimeRetentionDays(), $settings->exceptionsRetentionDays(), $occurrenceDays);

        $this->components->info("Deleted {$deleted['runtime']} runtime aggregates and {$deleted['exceptions']} exception groups.");

        return self::SUCCESS;
    }
}
