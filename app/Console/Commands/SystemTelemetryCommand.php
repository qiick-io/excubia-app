<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\StoreSystemTelemetryAction;
use Illuminate\Console\Command;

/**
 * Stores this app's own runtime and exceptions on the system project (#84). Scheduled every
 * minute while self-monitoring is on.
 */
class SystemTelemetryCommand extends Command
{
    protected $signature = 'excubia:system-telemetry';

    protected $description = 'Store this Excubia install\'s own runtime and exceptions on the system project';

    public function handle(StoreSystemTelemetryAction $storeTelemetry): int
    {
        $stored = $storeTelemetry->execute();

        $this->components->info("Stored {$stored['runtime']} runtime and {$stored['exceptions']} exception batches.");

        return self::SUCCESS;
    }
}
