<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TimeRangeData;
use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The project's AI tab (#129) over a time range (#113), from the `ai` and `aiTool` runtime
 * entries of excubia-core: estimated spend per chart bucket, calls, errors, failovers and p95
 * latency, the token split (uncached input, cache reads and writes, output, reasoning), the
 * breakdown by model, provider and agent, and tool calls with their failures. Counts and tokens
 * are estimated real amounts (`value / sample_rate`); costs are estimates from the price table
 * (AiPriceService), null for models it has no price for. The month to date against the
 * project's budget comes from the daily usage, which outlives the minute aggregates.
 *
 * Only counts, tokens, durations and class or model names are stored: core never sends prompts,
 * responses, tool arguments or results.
 *
 * @phpstan-import-type Series from ProjectRuntimeService
 * @phpstan-import-type Usage from AiPriceService
 */
class AiUsageService
{
    /** Rows per breakdown table. */
    public const TABLE_LIMIT = 20;

    public function __construct(
        private readonly ProjectRuntimeService $runtime,
        private readonly AiPriceService $prices,
        private readonly TimeSeriesService $timeSeries,
    ) {}

    /**
     * @return array<string, mixed>|null null when the project never pushed AI usage
     */
    public function summary(Project $project, TimeRangeData $range): ?array
    {
        $hasUsage = RuntimeAggregate::query()->where('project_id', $project->id)->whereIn('type', [RuntimeTypeEnum::Ai->value, RuntimeTypeEnum::AiTool->value])->exists()
            || $project->aiUsageDays()->exists();

        if (! $hasUsage) {
            return null;
        }

        $now = CarbonImmutable::instance(Date::now());
        $series = $this->seriesByPricePeriod($project, $range)->groupBy('type');
        $calls = $series->get(RuntimeTypeEnum::Ai->value, collect());
        $tools = $series->get(RuntimeTypeEnum::AiTool->value, collect());
        $monthStart = $now->startOfMonth();

        return [
            'range' => $range->value(),
            'isSampled' => $series->flatten(1)->contains(fn (array $one): bool => $one['sampleRate'] < 1),
            'pricesUpdatedOn' => $this->prices->updatedOn(),
            'totals' => [...$this->totals($calls), ...$this->runtime->percentiles($calls)],
            'timeline' => $this->timeline($project, $calls, $range),
            'models' => $this->breakdown($calls, fn (array $one): array => ['provider' => $one['dimensions']['provider'] ?? '', 'model' => $one['dimensions']['model'] ?? '']),
            'providers' => $this->breakdown($calls, fn (array $one): array => ['provider' => $one['dimensions']['provider'] ?? '']),
            // Operations other than agent runs have no agent: they are listed by operation.
            'agents' => $this->breakdown($calls, fn (array $one): array => ['agent' => $one['dimensions']['agent'] ?? null, 'operation' => $one['dimensions']['operation'] ?? '']),
            'tools' => $this->runtime->timedTable($tools, ['agent', 'tool']),
            'month' => [
                ...($month = $this->prices->costOfDays($project, $monthStart, $now)),
                'cost' => round($month['cost'], 4),
                'calls' => (int) round($month['calls']),
                'budget' => $project->ai_monthly_budget,
            ],
        ];
    }

    /**
     * The `ai` and `aiTool` series of the range, summed apart for each price period
     * (AiPriceService::changeDays()), so a range across a price change prices each side at its
     * own row (#202). Without a change day in the range this is one query.
     *
     * @return Collection<int, Series>
     */
    private function seriesByPricePeriod(Project $project, TimeRangeData $range): Collection
    {
        $types = [RuntimeTypeEnum::Ai, RuntimeTypeEnum::AiTool];
        $boundaries = array_map(
            fn (string $day): CarbonImmutable => CarbonImmutable::parse($day, 'UTC'),
            array_filter($this->prices->changeDays(), fn (string $day): bool => $day > $range->from->toDateString() && $day <= $range->until->toDateString()),
        );
        $series = collect();
        $start = $range->from;

        foreach ($boundaries as $boundary) {
            $series = $series->concat($this->runtime->series($project, $start, $types, $boundary));
            $start = $boundary;
        }

        return $series->concat($this->runtime->series($project, $start, $types, $range->until->addSecond()))->values();
    }

    /**
     * Estimated calls, errors, failovers and their rates, the token split and the cost of some
     * series.
     *
     * @param  Collection<int, Series>  $series
     * @return array<string, mixed>
     */
    private function totals(Collection $series): array
    {
        $estimate = fn (callable $value): float => (float) $series->sum(fn (array $one): float => (float) $value($one) / $one['sampleRate']);
        $count = $estimate(fn (array $one): int => $one['count']);
        $errors = $estimate(fn (array $one): int => $one['errorCount']);
        $failovers = $estimate(fn (array $one): ?float => $one['metrics']['failoverCount'] ?? null);
        $usage = $this->usage($series);
        $cost = 0.0;
        $hasUnpriced = false;

        // Priced per model, so each series takes its own price.
        foreach ($series->groupBy(fn (array $one): string => ($one['dimensions']['provider'] ?? '')."\0".($one['dimensions']['model'] ?? '')."\0".$this->prices->periodOf($one['lastAt'])) as $group) {
            /** @var Series $first */
            $first = $group->first();
            // Each series lies in one price period (seriesByPricePeriod), so its last minute prices it.
            $groupCost = $this->prices->cost($first['dimensions']['provider'] ?? '', $first['dimensions']['model'] ?? '', $this->usage($group), $first['lastAt']);
            $cost += $groupCost ?? 0.0;
            $hasUnpriced = $hasUnpriced || $groupCost === null;
        }

        $recorded = $series->sum('count');

        return [
            'count' => (int) round($count),
            'errors' => (int) round($errors),
            'failovers' => (int) round($failovers),
            'errorRate' => $count > 0 ? round($errors / $count * 100, 2) : null,
            'averageMs' => $recorded > 0 ? round($series->sum('totalMs') / $recorded, 1) : null,
            'uncachedInputTokens' => (int) round(max(0.0, $usage['inputTokens'] - $usage['cacheReadInputTokens'] - $usage['cacheWriteInputTokens'])),
            'cacheReadTokens' => (int) round($usage['cacheReadInputTokens']),
            'cacheWriteTokens' => (int) round($usage['cacheWriteInputTokens']),
            'outputTokens' => (int) round($usage['outputTokens']),
            'reasoningTokens' => (int) round($usage['reasoningTokens']),
            // Cost of the priced models; null when none of the usage has a price.
            'cost' => $hasUnpriced && $cost === 0.0 ? null : round($cost, 4),
            'hasUnpriced' => $hasUnpriced,
        ];
    }

    /**
     * The estimated usage of some series, a null count adding nothing.
     *
     * @param  Collection<int, Series>  $series
     * @return Usage
     */
    private function usage(Collection $series): array
    {
        $usage = [];

        foreach (RuntimeTypeEnum::AI_USAGE as $metric) {
            $usage[$metric] = (float) $series->sum(fn (array $one): float => (float) ($one['metrics'][$metric] ?? 0) / $one['sampleRate']);
        }

        /** @var Usage $usage */
        return $usage;
    }

    /**
     * Series grouped by the keys `$key` returns, each a row of those keys and its totals, the
     * largest cost (then calls) first.
     *
     * @param  Collection<int, Series>  $series
     * @param  callable(Series): array<string, string|null>  $key
     * @return list<array<string, mixed>>
     */
    private function breakdown(Collection $series, callable $key): array
    {
        return array_values($series
            ->groupBy(fn (array $one): string => (string) json_encode($key($one)))
            ->map(fn (Collection $group): array => [...$key($group->first()), ...$this->totals($group), ...$this->runtime->percentiles($group)])
            ->sort(fn (array $left, array $right): int => [$right['cost'] ?? -1, $right['count']] <=> [$left['cost'] ?? -1, $left['count']])
            ->take(self::TABLE_LIMIT)
            ->all());
    }

    /**
     * Estimated cost and calls per chart bucket: the usage of each series summed per bucket in
     * SQL (TimeSeriesService), priced per series. Audio seconds and search units are summed in
     * thousandths, so the whole-number points keep three decimals.
     *
     * @param  Collection<int, Series>  $calls
     * @return list<array{at: string, cost: float, count: int}>
     */
    private function timeline(Project $project, Collection $calls, TimeRangeData $range): array
    {
        $driver = (new RuntimeAggregate)->getConnection()->getDriverName();
        $sums = ['count' => '1.0 * count / sample_rate'];

        foreach (RuntimeTypeEnum::AI_USAGE as $metric) {
            $scale = in_array($metric, ['audioSeconds', 'searchUnits'], true) ? '1000.0' : '1.0';
            $sums[$metric] = "{$scale} * coalesce({$this->runtime->jsonNumber($driver, 'metrics', $metric)}, 0) / sample_rate";
        }

        $window = fn () => RuntimeAggregate::query()
            ->where('project_id', $project->id)
            ->where('type', RuntimeTypeEnum::Ai->value)
            ->where('bucket', '>=', $range->from)
            ->where('bucket', '<=', $range->until)
            ->toBase();
        /** @var array<string, list<array<string, int|string>>> $byKey */
        $byKey = $this->timeSeries->points($window(), 'bucket', $sums, $range, 'dimension_key');

        if ($byKey === []) {
            /** @var list<array{at: string, count: int}> $empty */
            $empty = $this->timeSeries->points($window(), 'bucket', ['count' => 'count'], $range);

            return array_map(fn (array $point): array => ['at' => $point['at'], 'cost' => 0.0, 'count' => 0], $empty);
        }

        $dimensions = $this->dimensionsByKey($window(), array_keys($byKey));
        $points = [];

        foreach ($byKey as $dimensionKey => $series) {
            foreach ($series as $index => $point) {
                $usage = [];

                foreach (RuntimeTypeEnum::AI_USAGE as $metric) {
                    $usage[$metric] = (float) $point[$metric] / (in_array($metric, ['audioSeconds', 'searchUnits'], true) ? 1000 : 1);
                }

                /** @var Usage $usage */
                $cost = $this->prices->cost($dimensions[$dimensionKey]['provider'] ?? '', $dimensions[$dimensionKey]['model'] ?? '', $usage, CarbonImmutable::parse((string) $point['at'])) ?? 0.0;
                $points[$index] ??= ['at' => (string) $point['at'], 'cost' => 0.0, 'count' => 0];
                $points[$index]['cost'] += $cost;
                $points[$index]['count'] += (int) $point['count'];
            }
        }

        ksort($points);

        return array_values(array_map(fn (array $point): array => [...$point, 'cost' => round($point['cost'], 4)], $points));
    }

    /**
     * The dimensions of each `ai` series key of the window, read from the first minute of each.
     *
     * @param  list<int|string>  $keys
     * @return array<string, array<string, string>>
     */
    private function dimensionsByKey(Builder $window, array $keys): array
    {
        $ids = $window->whereIn('dimension_key', $keys)->groupBy('dimension_key')->selectRaw('min(id) as id')->pluck('id')->all();
        $dimensions = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (RuntimeAggregate::query()->whereKey($chunk)->get(['dimension_key', 'dimensions']) as $aggregate) {
                $dimensions[$aggregate->dimension_key] = $aggregate->dimensions;
            }
        }

        return $dimensions;
    }
}
