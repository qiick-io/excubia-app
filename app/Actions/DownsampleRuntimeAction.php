<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuntimeTypeEnum;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Folds the runtime minutes older than the per-minute window into hourly rows (#212), so a busy
 * project keeps about one row per series and hour instead of sixty and the disk stays small over
 * the whole runtime retention.
 *
 * The hourly row stays in `runtime_aggregates`, in the same series (type, sample rate, dimension
 * key with release): the series' latest minute of the hour absorbs the others, which are deleted.
 * Every reader keeps working unchanged, because each one sums, maxes or merges the rows of its
 * window and every window or chart bucket that reaches past the per-minute window starts on a
 * whole hour (24 h, 7 d and 14 d buckets, UTC days): the totals, histograms, percentiles, charts
 * and `lastAt` are the same before and after the fold. The merge follows the reads, not the
 * ingest merge: counts, errors and durations add up and maxima keep the larger value, with null
 * only when every minute is null; metrics merge by RuntimeTypeEnum::mergeMetrics (sums, maxima,
 * server gauges by max, `isContainer` by or, health by the worst status and severity, the
 * fingerprint union); histograms add up element by element over the minutes that have one.
 *
 * Idempotent and safe to re-run: an hour whose series hold one row each has nothing to fold. A
 * run folds the hours from the previous run's end (minus one day, for late pushes and missed
 * runs) up to the whole hour before the cutoff; without that mark (first run, cleared cache) it
 * starts at the oldest stored minute. Each project-hour is one transaction that locks its rows,
 * so a concurrent push either lands before the fold (and is folded) or after it (and is folded on
 * the next run).
 */
class DownsampleRuntimeAction
{
    /** The cache key of the hour the last run folded up to (Unix time). */
    public const FOLDED_UNTIL_KEY = 'excubia.runtime_folded_until';

    /** Hours before the last run's end that a run folds again, for late pushes and missed runs. */
    public const LOOKBACK_HOURS = 24;

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
            $projectIds = RuntimeAggregate::query()
                ->where('bucket', '>=', $hour)
                ->where('bucket', '<', $hour->addHour())
                ->distinct()
                ->pluck('project_id');

            foreach ($projectIds as $projectId) {
                $folded += $this->foldHour((int) $projectId, $hour);
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
            return CarbonImmutable::createFromTimestampUTC($foldedUntil)->subHours(self::LOOKBACK_HOURS);
        }

        $oldest = RuntimeAggregate::query()->min('bucket');

        return $oldest === null ? $until : CarbonImmutable::parse((string) $oldest, 'UTC')->startOfHour();
    }

    /**
     * Folds one project's hour: per series, the latest minute absorbs the others.
     *
     * @return int the rows deleted
     */
    private function foldHour(int $projectId, CarbonImmutable $hour): int
    {
        return DB::transaction(function () use ($projectId, $hour): int {
            $series = RuntimeAggregate::query()
                ->where('project_id', $projectId)
                ->where('bucket', '>=', $hour)
                ->where('bucket', '<', $hour->addHour())
                ->lockForUpdate()
                ->get()
                ->groupBy(fn (RuntimeAggregate $aggregate): string => implode('|', [$aggregate->type->value, sprintf('%.4F', $aggregate->sample_rate), $aggregate->dimension_key]))
                ->filter(fn (Collection $minutes): bool => $minutes->count() > 1);

            $deletedIds = [];

            foreach ($series as $minutes) {
                $sorted = $minutes->sortBy([['bucket', 'asc'], ['id', 'asc']])->values();
                /** @var RuntimeAggregate $latest */
                $latest = $sorted->pop();
                $latest->forceFill($sorted->reduce(fn (array $merged, RuntimeAggregate $minute): array => $this->merged($latest->type, $merged, $minute), $latest->only(['count', 'error_count', 'total_ms', 'max_ms', 'metrics', 'histogram'])));
                $latest->save();
                array_push($deletedIds, ...$sorted->pluck('id')->all());
            }

            foreach (array_chunk($deletedIds, 500) as $chunk) {
                RuntimeAggregate::query()->whereKey($chunk)->delete();
            }

            return count($deletedIds);
        });
    }

    /**
     * Adds one minute to the hour, as the reads sum the rows of a window (see the class docblock).
     *
     * @param  array{count: int, error_count: int, total_ms: float|null, max_ms: float|null, metrics: array<string, mixed>|null, histogram: list<int>|null}  $hour
     * @return array{count: int, error_count: int, total_ms: float|null, max_ms: float|null, metrics: array<string, mixed>|null, histogram: list<int>|null}
     */
    private function merged(RuntimeTypeEnum $type, array $hour, RuntimeAggregate $minute): array
    {
        return [
            'count' => $hour['count'] + $minute->count,
            'error_count' => $hour['error_count'] + $minute->error_count,
            'total_ms' => $hour['total_ms'] === null && $minute->total_ms === null ? null : (float) $hour['total_ms'] + (float) $minute->total_ms,
            'max_ms' => $hour['max_ms'] === null || $minute->max_ms === null ? $hour['max_ms'] ?? $minute->max_ms : max($hour['max_ms'], $minute->max_ms),
            'metrics' => $type->metrics() === [] ? null : $type->mergeMetrics($hour['metrics'] ?? [], $minute->metrics ?? []),
            'histogram' => $hour['histogram'] === null || $minute->histogram === null
                ? $hour['histogram'] ?? $minute->histogram
                : array_map(fn (int $left, int $right): int => $left + $right, $hour['histogram'], $minute->histogram),
        ];
    }
}
