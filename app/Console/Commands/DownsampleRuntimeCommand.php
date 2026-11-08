<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DownsampleRuntimeAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Folds the runtime minutes older than `excubia.runtime_minute_hours` into hourly rows (#212).
 * Scheduled hourly; re-running it folds nothing new.
 */
class DownsampleRuntimeCommand extends Command
{
    /**
     * The shortest per-minute window: the 1 h and 6 h ranges read minutes (their chart buckets
     * are 1 and 10 minutes), the 24 h range and longer ones read whole hours.
     */
    public const MINIMUM_MINUTE_HOURS = 24;

    protected $signature = 'excubia:downsample-runtime';

    protected $description = 'Fold runtime minutes older than the per-minute window into hourly rows';

    public function handle(DownsampleRuntimeAction $downsampleRuntime): int
    {
        $hours = max(self::MINIMUM_MINUTE_HOURS, (int) config('excubia.runtime_minute_hours'));
        $folded = $downsampleRuntime->execute(CarbonImmutable::instance(Date::now())->subHours($hours));

        $this->components->info("Folded {$folded} runtime minutes older than {$hours} hours into hourly rows.");

        return self::SUCCESS;
    }
}
