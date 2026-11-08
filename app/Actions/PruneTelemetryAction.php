<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AiUsageDay;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\IngestRejection;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Date;

/**
 * Bounds the runtime and exception data: runtime aggregates (hourly rows past the per-minute
 * window, #212), their hourly rollups (#137) and scheduled-task runs (#110) older than the runtime
 * retention, exception groups not seen within the exceptions retention (#113), the occurrences
 * older than the shorter of the occurrence window and that retention (#214: the charts and "In
 * range" counts reach back 14 days at most, while a group keeps its own total, first and last
 * seen and releases), the batch ids older than BATCH_ID_DAYS, the alert history (#114) older than
 * the longer of the two retentions, the mutes that ended, and the
 * push rejection counters (#117) older than two days. The daily AI usage (#129) is kept for the
 * longer of the runtime retention and AI_USAGE_DAYS, so the monthly budget and the digest see
 * the current and the previous month.
 */
class PruneTelemetryAction
{
    /** Days of daily AI usage kept at least: this month and the previous one. */
    public const AI_USAGE_DAYS = 62;

    /**
     * Days a pushed batch id is kept to make a retry idempotent (#212). excubia-core retries a
     * batch whose answer it missed on its next run, minutes later; at 90 days of exceptions
     * retention the ids of a busy project took more disk than its folded runtime.
     */
    public const BATCH_ID_DAYS = 14;

    /** Days of exception occurrences kept by default: the longest time range (14 d, #214). */
    public const OCCURRENCE_DAYS = 14;

    /**
     * @return array{runtime: int, exceptions: int}
     */
    public function execute(int $runtimeDays, int $exceptionDays, int $occurrenceDays = self::OCCURRENCE_DAYS): array
    {
        $runtime = RuntimeAggregate::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        RuntimeHourlyRollup::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        ScheduledTaskRun::query()->where('bucket', '<', Date::now()->subDays($runtimeDays))->delete();
        ExceptionOccurrence::query()->where('bucket', '<', Date::now()->subDays(min($occurrenceDays, $exceptionDays)))->delete();
        $exceptions = ExceptionGroup::query()->where('last_seen_at', '<', Date::now()->subDays($exceptionDays))->delete();
        PushedBatch::query()->where('received_at', '<', Date::now()->subDays(self::BATCH_ID_DAYS))->delete();
        AlertLog::query()->where('created_at', '<', Date::now()->subDays(max($runtimeDays, $exceptionDays)))->delete();
        AlertMute::query()->where('until', '<=', Date::now())->delete();
        AiUsageDay::query()->where('day', '<', Date::now()->subDays(max($runtimeDays, self::AI_USAGE_DAYS))->toDateString())->delete();
        IngestRejection::query()->where('bucket', '<', Date::now()->subDays(2))->delete();

        return ['runtime' => $runtime, 'exceptions' => $exceptions];
    }
}
