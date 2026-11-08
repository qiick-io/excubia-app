<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Folds the exception occurrences older than the per-minute window into hourly rows (#214), as
 * DownsampleRuntimeAction does for the runtime minutes (#212): a group's latest minute of the
 * hour absorbs the counts of its other minutes, which are deleted.
 *
 * The readers stay unchanged and give the same numbers: the Exceptions tab's "In range" count
 * and each group's chart sum the occurrences of their window or bucket, and every window and
 * bucket that reaches past the per-minute window (at least 24 hours) starts on a whole hour. The
 * group row keeps its own total, first and last seen and releases, which the digest and the
 * overview read; this fold never touches it.
 *
 * Idempotent and safe to re-run, with the same window as the runtime fold: from the previous
 * run's end minus DownsampleRuntimeAction::LOOKBACK_HOURS (for late pushes and missed runs), or
 * from the oldest stored minute without that mark, up to the whole hour before the cutoff. Each
 * group-hour is one transaction that first locks the group row, as the ingest does
 * (StorePushedExceptionsAction), so a concurrent push lands either before the fold or after it.
 */
class DownsampleExceptionOccurrencesAction
{
    /** The cache key of the hour the last run folded up to (Unix time). */
    public const FOLDED_UNTIL_KEY = 'excubia.exception_occurrences_folded_until';

    /**
     * @param  CarbonImmutable  $before  minutes before this moment are folded (whole hours only)
     * @return int the minute rows folded away (deleted)
     */
    public function execute(CarbonImmutable $before): int
    {
        $until = $before->utc()->startOfHour();
        $from = $this->firstHour($until);
        $folded = 0;

        for ($hour = $from; $hour < $until; $hour = $hour->addHour()) {
            $groupIds = ExceptionOccurrence::query()
                ->where('bucket', '>=', $hour)
                ->where('bucket', '<', $hour->addHour())
                ->groupBy('exception_group_id')
                ->havingRaw('count(*) > 1')
                ->pluck('exception_group_id');

            foreach ($groupIds as $groupId) {
                $folded += $this->foldHour((int) $groupId, $hour);
            }
        }

        Cache::forever(self::FOLDED_UNTIL_KEY, $until->getTimestamp());

        return $folded;
    }

    /** The first hour of this run: the lookback before the last run's end, or the oldest minute. */
    private function firstHour(CarbonImmutable $until): CarbonImmutable
    {
        $foldedUntil = Cache::get(self::FOLDED_UNTIL_KEY);

        if (is_int($foldedUntil)) {
            return CarbonImmutable::createFromTimestampUTC($foldedUntil)->subHours(DownsampleRuntimeAction::LOOKBACK_HOURS);
        }

        $oldest = ExceptionOccurrence::query()->min('bucket');

        return $oldest === null ? $until : CarbonImmutable::parse((string) $oldest, 'UTC')->startOfHour();
    }

    /**
     * Folds one group's hour into its latest minute.
     *
     * @return int the rows deleted
     */
    private function foldHour(int $groupId, CarbonImmutable $hour): int
    {
        return DB::transaction(function () use ($groupId, $hour): int {
            ExceptionGroup::query()->whereKey($groupId)->lockForUpdate()->first();

            $minutes = ExceptionOccurrence::query()
                ->where('exception_group_id', $groupId)
                ->where('bucket', '>=', $hour)
                ->where('bucket', '<', $hour->addHour())
                ->orderBy('bucket')
                ->lockForUpdate()
                ->get();

            if ($minutes->count() < 2) {
                return 0;
            }

            /** @var ExceptionOccurrence $latest */
            $latest = $minutes->pop();
            $latest->forceFill(['count' => $latest->count + $minutes->sum('count')])->save();
            ExceptionOccurrence::query()->whereKey($minutes->modelKeys())->delete();

            return $minutes->count();
        });
    }
}
