<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ExceptionGroup;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Date;

/**
 * Bounds the runtime and exception data: runtime aggregates and scheduled-task runs (#110) older
 * than the runtime retention, exception groups not seen within the exceptions retention, and the
 * batch ids older than the longer of the two (a retry that late would be a new batch anyway).
 */
class PruneTelemetryAction
{
    /**
     * @return array{runtime: int, exceptions: int}
     */
    public function execute(int $runtimeDays, int $exceptionDays): array
    {
        $runtime = RuntimeAggregate::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        ScheduledTaskRun::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        $exceptions = ExceptionGroup::query()->where('last_seen', '<', Date::now()->subDays($exceptionDays))->delete();
        PushedBatch::query()->where('received_at', '<', Date::now()->subDays(max($runtimeDays, $exceptionDays)))->delete();

        return ['runtime' => $runtime, 'exceptions' => $exceptions];
    }
}
