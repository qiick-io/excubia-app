<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Date;

/**
 * Bounds the runtime and exception data: runtime aggregates, their hourly rollups (#137) and
 * scheduled-task runs (#110) older than the runtime retention, exception groups not seen within
 * the exceptions retention (and older occurrences of the others, #113), the batch ids and the alert history (#114) older than the
 * longer of the two (a retry that late would be a new batch anyway), and the mutes that ended.
 */
class PruneTelemetryAction
{
    /**
     * @return array{runtime: int, exceptions: int}
     */
    public function execute(int $runtimeDays, int $exceptionDays): array
    {
        $runtime = RuntimeAggregate::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        RuntimeHourlyRollup::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        ScheduledTaskRun::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        ExceptionOccurrence::query()->where('bucket', '<', Date::now()->subDays($exceptionDays))->delete();
        $exceptions = ExceptionGroup::query()->where('last_seen', '<', Date::now()->subDays($exceptionDays))->delete();
        PushedBatch::query()->where('received_at', '<', Date::now()->subDays(max($runtimeDays, $exceptionDays)))->delete();
        AlertLog::query()->where('created_at', '<', Date::now()->subDays(max($runtimeDays, $exceptionDays)))->delete();
        AlertMute::query()->where('until', '<=', Date::now())->delete();

        return ['runtime' => $runtime, 'exceptions' => $exceptions];
    }
}
