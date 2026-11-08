<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuntimeTypeEnum;
use App\Enums\TimeRangeEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The runtime of a project over a time range (#24, #107, #113), from the aggregates excubia-core
 * pushes: one card per runtime type. Counts are estimated real counts (`count / sample_rate`);
 * `isSampled` tells the page to show them as approximate. Durations, memory and repeats are the
 * recorded ones; p50, p95 and p99 come from the optional latency histograms (core
 * `$defs.histogram`).
 *
 * SQL sums each series (type, dimensions with release, sample rate) over the window, histogram
 * buckets and summed metrics included, so PHP only folds series that differ by release or sample
 * rate. The charts are summed per bucket of the range in SQL (TimeSeriesService). Server gauges
 * are readings: the card shows each server's latest sample. The deploys of the range (#106) come
 * along for the chart markers.
 *
 * @phpstan-type Series array{type: string, sampleRate: float, dimensions: array<string, string>, count: int, errorCount: int, totalMs: float|null, maxMs: float|null, lastAt: CarbonImmutable, histogram: list<float>|null, metrics: array<string, float|null>}
 */
class ProjectRuntimeService
{
    /** Rows per table, so the page stays light. */
    public const TABLE_LIMIT = 20;

    /** Upper bounds (inclusive, ms) of histogram buckets 0 to 10; bucket 11 is the overflow. */
    private const HISTOGRAM_BOUNDS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /** Log levels of core's `logLevel`, most severe first. */
    private const LOG_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'unknown'];

    /** Histogram bucket indexes (RuntimeTypeEnum::HISTOGRAM_LENGTH of them). */
    private const HISTOGRAM_INDEXES = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];

    /**
     * The summed and maxed metrics of RuntimeTypeEnum::metrics(), server gauges aside (they are
     * readings, see servers()), read in SQL. A test keeps the two lists in step.
     */
    public const SQL_METRICS = [
        'totalMb' => 'sum', 'maxMb' => 'max', 'hits' => 'sum', 'misses' => 'sum', 'writes' => 'sum', 'deletes' => 'sum', 'totalRepeats' => 'sum', 'maxRepeats' => 'max',
        // `ai` (#129), read only by AiUsageService.
        'failoverCount' => 'sum', 'inputTokens' => 'sum', 'outputTokens' => 'sum', 'cacheReadInputTokens' => 'sum', 'cacheWriteInputTokens' => 'sum',
        'reasoningTokens' => 'sum', 'imageInputTokens' => 'sum', 'imageOutputTokens' => 'sum', 'audioSeconds' => 'sum', 'searchUnits' => 'sum',
        // `octane` (#168); its `maxMb` is the one above.
        'workerStarts' => 'sum', 'workerStops' => 'sum', 'requests' => 'sum', 'tasks' => 'sum', 'ticks' => 'sum', 'totalRequestsAtStop' => 'sum', 'maxRequestsAtStop' => 'max', 'maxGrowthMb' => 'max',
        // `reverb` (#175).
        'connectionsOpened' => 'sum', 'connectionsRejected' => 'sum', 'connectionsPruned' => 'sum', 'messagesReceived' => 'sum', 'messagesSent' => 'sum',
        'publicChannelsCreated' => 'sum', 'privateChannelsCreated' => 'sum', 'presenceChannelsCreated' => 'sum',
        'publicChannelsRemoved' => 'sum', 'privateChannelsRemoved' => 'sum', 'presenceChannelsRemoved' => 'sum', 'maxConnections' => 'max',
        // `billingWebhook` (#178).
        'handledCount' => 'sum', 'paymentFailedCount' => 'sum', 'paymentActionRequiredCount' => 'sum', 'subscriptionCanceledCount' => 'sum',
        // `auth` (#180).
        'loginCount' => 'sum', 'lockoutCount' => 'sum', 'tokenAuthenticationCount' => 'sum',
    ];

    /**
     * Growth of a worker's memory since its first request (MiB) above which the Octane card
     * flags a possible leak (#168).
     *
     * ponytail: one fixed threshold for every app; make it a setting if apps with large
     * per-request caches see false alarms.
     */
    public const OCTANE_LEAK_GROWTH_MB = 32;

    /** The check whose `details` carry `octane.server` and `octane.max_requests` (core #213). */
    private const OCTANE_CONFIG_CHECK = 'laravel-octane-config';

    /** The check whose `details` carry the Reverb server's `isReachable` and `latencyMs` (core #215). */
    private const REVERB_CHECK = 'laravel-reverb';

    /** Channel prefixes of the `reverb` counters (`publicChannelsCreated` …). */
    private const REVERB_CHANNEL_PREFIXES = ['public', 'private', 'presence'];

    /** The types of the Runtime tab; the AI usage types (#129) have their own tab (AiUsageService). */
    private const AI_TYPES = [RuntimeTypeEnum::Ai, RuntimeTypeEnum::AiTool];

    /** The levels the error-log chart counts. */
    private const ERROR_LOG_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    public function __construct(private readonly TimeSeriesService $timeSeries) {}

    /**
     * @return array<string, mixed>|null null when the project never pushed runtime data
     */
    public function summary(Project $project, TimeRangeEnum $range = TimeRangeEnum::DEFAULT): ?array
    {
        if ($project->skipped_runtime_aggregates === 0 && ! $project->runtimeAggregates()->exists()) {
            return null;
        }

        $now = CarbonImmutable::instance(Date::now());
        $from = $range->start($now);
        $series = $this->series($project, $from, array_filter(RuntimeTypeEnum::cases(), fn (RuntimeTypeEnum $type): bool => ! in_array($type, self::AI_TYPES, true)));
        $byType = $series->groupBy('type');
        $of = fn (RuntimeTypeEnum $type): Collection => $byType->get($type->value, collect());
        $requests = $of(RuntimeTypeEnum::Request);

        return [
            'range' => $range->value,
            'hours' => intdiv($range->seconds(), 3600),
            'isSampled' => $series->contains(fn (array $one): bool => $one['sampleRate'] < 1),
            'skippedAggregates' => $project->skipped_runtime_aggregates,
            'requests' => [
                ...$this->totals($requests),
                'timeline' => array_map(
                    fn (array $point): array => ['at' => $point['at'], 'ok' => (int) $point['count'] - (int) $point['errors'], 'errors' => $point['errors']],
                    $this->timeline($this->window($project, RuntimeTypeEnum::Request, $from), $range, $now),
                ),
                'routes' => $this->timedTable($requests, ['route', 'method']),
            ],
            'slowQueries' => $this->timedTable($of(RuntimeTypeEnum::SlowQuery), ['sql', 'connection']),
            'jobs' => $this->timedTable($of(RuntimeTypeEnum::Job), ['job', 'queue']),
            'scheduledTasks' => $this->timedTable($of(RuntimeTypeEnum::ScheduledTask), ['task']),
            'outgoingHttp' => $this->timedTable($of(RuntimeTypeEnum::OutgoingHttp), ['host']),
            'queueWait' => $this->timedTable($of(RuntimeTypeEnum::QueueWait), ['job', 'queue']),
            'commands' => $this->timedTable($of(RuntimeTypeEnum::Command), ['command']),
            'requestMemory' => $this->memoryTable($of(RuntimeTypeEnum::RequestMemory), ['route', 'method']),
            'jobMemory' => $this->memoryTable($of(RuntimeTypeEnum::JobMemory), ['job', 'queue']),
            'cache' => $this->cache($of(RuntimeTypeEnum::Cache)),
            'mail' => $this->countTable($of(RuntimeTypeEnum::Mail), ['mailable', 'mailer']),
            'notifications' => $this->countTable($of(RuntimeTypeEnum::Notification), ['notification', 'channel']),
            'logLevels' => $this->logLevels($project, $of(RuntimeTypeEnum::LogLevel), $range, $now),
            'deprecations' => $this->countTable($of(RuntimeTypeEnum::Deprecation), ['source', 'message', 'phpVersion']),
            'duplicateQueries' => $this->duplicateQueries($of(RuntimeTypeEnum::DuplicateQuery)),
            'servers' => $this->servers($project, $of(RuntimeTypeEnum::Server)),
            'octane' => $this->octane($project, $of(RuntimeTypeEnum::Octane)),
            'reverb' => $this->reverb($project, $of(RuntimeTypeEnum::Reverb)),
            'billing' => $this->billing($of(RuntimeTypeEnum::BillingWebhook)),
            'auth' => $this->auth($of(RuntimeTypeEnum::Auth)),
            'deploys' => $this->timeSeries->deploys($project, $range, $now),
        ];
    }

    /**
     * The project's aggregates of one type (or all) since the start of the window, as a base query.
     */
    private function window(Project $project, ?RuntimeTypeEnum $type, CarbonImmutable $from): Builder
    {
        return RuntimeAggregate::query()
            ->where('project_id', $project->id)
            ->when($type !== null, fn ($query) => $query->where('type', $type?->value))
            ->where('bucket', '>=', $from)
            ->toBase();
    }

    /**
     * Estimated events and errors per bucket of the range, from the given query of minutes.
     *
     * @return list<array{at: string, count: int, errors: int}>
     */
    private function timeline(Builder $query, TimeRangeEnum $range, CarbonImmutable $now): array
    {
        /** @var list<array{at: string, count: int, errors: int}> */
        return $this->timeSeries->points($query, 'bucket', ['count' => '1.0 * count / sample_rate', 'errors' => '1.0 * error_count / sample_rate'], $range, $now);
    }

    /**
     * Every series of the given types in the window, summed in SQL: counts, durations, histogram
     * buckets and the summed or maxed metrics of those types (server gauges excluded, see
     * servers()). A JSON number is cast to decimal so MySQL and PostgreSQL add it like SQLite
     * does. A series' histogram sums only the minutes that have one, and is null when none has.
     * Metrics of other types are null.
     *
     * @param  array<int, RuntimeTypeEnum>  $types
     * @return Collection<int, Series>
     */
    public function series(Project $project, CarbonImmutable $from, array $types): Collection
    {
        $query = $this->window($project, null, $from)->whereIn('type', array_map(fn (RuntimeTypeEnum $type): string => $type->value, $types));
        $driver = (new RuntimeAggregate)->getConnection()->getDriverName();
        $metrics = array_intersect_key(self::SQL_METRICS, array_merge(...array_map(fn (RuntimeTypeEnum $type): array => $type->metrics(), $types)));
        $selects = ['min(id) as id', 'sum(count) as count', 'sum(error_count) as error_count', 'sum(total_ms) as total_ms', 'max(max_ms) as max_ms', 'max(bucket) as last_at', 'count(histogram) as histogram_rows'];

        foreach (self::HISTOGRAM_INDEXES as $i) {
            $selects[] = 'sum('.$this->jsonNumber($driver, 'histogram', $i).') as histogram_'.$i;
        }

        foreach ($metrics as $metric => $merge) {
            $selects[] = $merge.'('.$this->jsonNumber($driver, 'metrics', $metric).') as metric_'.$metric;
        }

        $rows = $query
            ->select(['type', 'dimension_key', 'sample_rate'])
            ->addSelect(array_map(fn (string $select): Expression => new Expression($select), $selects))
            ->groupBy('type', 'dimension_key', 'sample_rate')
            ->get();

        /** @var array<int, array<string, string>> $dimensions */
        $dimensions = [];

        foreach ($rows->pluck('id')->chunk(500) as $ids) {
            foreach (RuntimeAggregate::query()->whereKey($ids->all())->get(['id', 'dimensions']) as $aggregate) {
                $dimensions[$aggregate->id] = $aggregate->dimensions;
            }
        }

        return $rows->map(fn (object $row): array => $this->toSeries((array) $row, $dimensions))->values();
    }

    /**
     * One row of the series query as a series, with its dimensions.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, string>>  $dimensions  by the series' first id
     * @return Series
     */
    private function toSeries(array $row, array $dimensions): array
    {
        // Lower case: PostgreSQL folds the unquoted metric aliases.
        $values = array_change_key_case($row);
        $histogram = null;

        if ((int) $values['histogram_rows'] > 0) {
            $histogram = [];

            foreach (self::HISTOGRAM_INDEXES as $i) {
                $histogram[] = (float) $values["histogram_{$i}"];
            }
        }

        $metrics = [];

        foreach (array_keys(self::SQL_METRICS) as $metric) {
            $value = $values['metric_'.strtolower($metric)] ?? null;
            $metrics[$metric] = $value === null ? null : (float) $value;
        }

        return [
            'type' => (string) $values['type'],
            'sampleRate' => (float) $values['sample_rate'],
            'dimensions' => $dimensions[(int) $values['id']] ?? [],
            'count' => (int) $values['count'],
            'errorCount' => (int) $values['error_count'],
            'totalMs' => $values['total_ms'] === null ? null : (float) $values['total_ms'],
            'maxMs' => $values['max_ms'] === null ? null : (float) $values['max_ms'],
            'lastAt' => CarbonImmutable::parse((string) $values['last_at'], 'UTC'),
            'histogram' => $histogram,
            'metrics' => $metrics,
        ];
    }

    /**
     * A number inside a JSON column as SQL, cast to decimal so MySQL and PostgreSQL (which read
     * JSON values as text) add and compare it like SQLite does. Missing or JSON null is NULL.
     *
     * @param  'histogram'|'metrics'  $column
     * @param  key-of<self::SQL_METRICS>|value-of<self::HISTOGRAM_INDEXES>  $key  a metric name or a histogram index
     * @return literal-string
     */
    public function jsonNumber(string $driver, string $column, string|int $key): string
    {
        $value = match ($driver) {
            'mysql', 'mariadb' => is_int($key) ? "json_unquote(json_extract({$column}, '$[{$key}]'))" : "json_unquote(json_extract({$column}, '$.\"{$key}\"'))",
            'pgsql' => is_int($key) ? "({$column}->>{$key})" : "({$column}->>'{$key}')",
            default => is_int($key) ? "json_extract({$column}, '$[{$key}]')" : "json_extract({$column}, '$.\"{$key}\"')",
        };

        return "cast({$value} as decimal(20, 4))";
    }

    /**
     * Estimated count and errors, average and slowest duration, and percentiles of the series.
     *
     * @param  Collection<int, Series>  $series
     * @return array{count: int, errors: int, averageMs: float|null, maxMs: float|null, p50: float|null, p95: float|null, p99: float|null}
     */
    private function totals(Collection $series): array
    {
        $recorded = $series->sum('count');

        return [
            'count' => $this->estimate($series, fn (array $one): float => $one['count']),
            'errors' => $this->estimate($series, fn (array $one): float => $one['errorCount']),
            'averageMs' => $recorded > 0 ? round($series->sum('totalMs') / $recorded, 1) : null,
            'maxMs' => $series->isEmpty() ? null : round((float) $series->max('maxMs'), 1),
            ...$this->percentiles($series->reject(fn (array $one): bool => ($one['dimensions']['outcome'] ?? '') === 'skipped')),
        ];
    }

    /**
     * p50, p95 and p99 by the recipe of core `$defs.histogram`: histograms summed element by
     * element (each divided by its sample rate), then linear interpolation inside the bucket that
     * holds the rank, never above the slowest duration. Series without a histogram are left out;
     * all null when none has one. Skipped scheduled tasks (0 ms) are left out by totals().
     *
     * @param  Collection<int, Series>  $series
     * @return array{p50: float|null, p95: float|null, p99: float|null}
     */
    public function percentiles(Collection $series): array
    {
        $withHistogram = $series->filter(fn (array $one): bool => $one['histogram'] !== null);
        $buckets = array_fill(0, RuntimeTypeEnum::HISTOGRAM_LENGTH, 0.0);

        foreach ($withHistogram as $one) {
            foreach ((array) $one['histogram'] as $i => $value) {
                $buckets[$i] += $value / $one['sampleRate'];
            }
        }

        $total = array_sum($buckets);
        $max = (float) $withHistogram->max('maxMs');
        $result = ['p50' => null, 'p95' => null, 'p99' => null];

        if ($total <= 0) {
            return $result;
        }

        foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $key => $share) {
            $rank = $share * $total;
            $cumulative = 0.0;

            foreach ($buckets as $i => $value) {
                $previous = $cumulative;
                $cumulative += $value;

                if ($value <= 0 || $cumulative < $rank) {
                    continue;
                }

                $lower = $i === 0 ? 0 : self::HISTOGRAM_BOUNDS[$i - 1];
                $upper = self::HISTOGRAM_BOUNDS[$i] ?? max($max, 10000);
                $result[$key] = round(min($lower + ($upper - $lower) * ($rank - $previous) / $value, $max), 1);

                break;
            }
        }

        return $result;
    }

    /**
     * The estimated real total of a per-series value (`value / sample_rate`), rounded.
     *
     * @param  Collection<int, Series>  $series
     * @param  callable(Series): (int|float|null)  $value
     */
    private function estimate(Collection $series, callable $value): int
    {
        return (int) round($series->sum(fn (array $one): float => (float) $value($one) / $one['sampleRate']));
    }

    /**
     * Series grouped by the given dimensions (across releases and sample rates), each group
     * mapped to a row that starts with those dimensions, the largest `$sortBy` first.
     *
     * @param  Collection<int, Series>  $series
     * @param  list<string>  $dimensions
     * @param  callable(Collection<int, Series>): array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function table(Collection $series, array $dimensions, callable $row, string $sortBy = 'count'): array
    {
        return array_values($series
            ->groupBy(fn (array $one): string => implode("\0", array_map(fn (string $dimension): string => $one['dimensions'][$dimension] ?? '', $dimensions)))
            ->map(function (Collection $group) use ($dimensions, $row): array {
                /** @var Series $first */
                $first = $group->first();

                return [
                    ...array_combine($dimensions, array_map(fn (string $dimension): string => $first['dimensions'][$dimension] ?? '', $dimensions)),
                    ...$row($group),
                    'lastAt' => $group->max('lastAt')?->toIso8601ZuluString(),
                ];
            })
            ->sortByDesc($sortBy)
            ->take(self::TABLE_LIMIT)
            ->all());
    }

    /**
     * Timed series (requests, slow queries, jobs, tasks, outgoing HTTP, queue wait, commands):
     * totals and percentiles, with the outcome split for jobs and scheduled tasks.
     *
     * @param  Collection<int, Series>  $series
     * @param  list<string>  $dimensions
     * @return list<array<string, mixed>>
     */
    public function timedTable(Collection $series, array $dimensions): array
    {
        return $this->table($series, $dimensions, fn (Collection $group): array => [
            ...$this->totals($group),
            'outcomes' => $group->groupBy(fn (array $one): string => $one['dimensions']['outcome'] ?? '')
                ->filter(fn (Collection $rows, string $outcome): bool => $outcome !== '')
                ->map(fn (Collection $rows): int => $this->estimate($rows, fn (array $one): float => $one['count']))
                ->all(),
        ]);
    }

    /**
     * Peak memory per route or job: the average and the largest peak (MB) of the recorded runs.
     *
     * @param  Collection<int, Series>  $series
     * @param  list<string>  $dimensions
     * @return list<array<string, mixed>>
     */
    private function memoryTable(Collection $series, array $dimensions): array
    {
        return $this->table($series, $dimensions, function (Collection $group): array {
            $recorded = $group->sum('count');

            return [
                'count' => $this->estimate($group, fn (array $one): float => $one['count']),
                'averageMb' => $recorded > 0 ? round($group->sum(fn (array $one): float => (float) $one['metrics']['totalMb']) / $recorded, 1) : null,
                'maxMb' => $group->isEmpty() ? null : round((float) $group->max(fn (array $one): float => (float) $one['metrics']['maxMb']), 1),
            ];
        });
    }

    /**
     * Counted series (mail, notifications, deprecations): estimated count and failures.
     *
     * @param  Collection<int, Series>  $series
     * @param  list<string>  $dimensions
     * @return list<array<string, mixed>>
     */
    private function countTable(Collection $series, array $dimensions): array
    {
        return $this->table($series, $dimensions, fn (Collection $group): array => [
            'count' => $this->estimate($group, fn (array $one): float => $one['count']),
            'errors' => $this->estimate($group, fn (array $one): float => $one['errorCount']),
        ]);
    }

    /**
     * Cache events per store and key prefix with their hit rate (hits / (hits + misses), in %),
     * and the hit rate of all of them.
     *
     * @param  Collection<int, Series>  $series
     * @return array{hits: int, misses: int, hitRate: float|null, rows: list<array<string, mixed>>}
     */
    private function cache(Collection $series): array
    {
        $counters = fn (Collection $group): array => [
            'hits' => $hits = $this->estimate($group, fn (array $one): ?float => $one['metrics']['hits']),
            'misses' => $misses = $this->estimate($group, fn (array $one): ?float => $one['metrics']['misses']),
            'hitRate' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 1) : null,
        ];

        return [
            ...$counters($series),
            'rows' => $this->table($series, ['store', 'keyPrefix'], fn (Collection $group): array => [
                'count' => $this->estimate($group, fn (array $one): float => $one['count']),
                ...$counters($group),
                'writes' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['writes']),
                'deletes' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['deletes']),
            ]),
        ];
    }

    /**
     * Log entries per level (most severe first, then levels this app does not know), per level and channel (null for entries
     * without one), and error-level entries per bucket of the range.
     *
     * @param  Collection<int, Series>  $series
     * @return array{levels: list<array{level: string, count: int}>, rows: list<array<string, mixed>>, timeline: list<array{at: string, count: int}>}
     */
    private function logLevels(Project $project, Collection $series, TimeRangeEnum $range, CarbonImmutable $now): array
    {
        $byLevel = $series->groupBy(fn (array $one): string => $one['dimensions']['level'] ?? 'unknown');
        $levels = [];

        // Levels this app does not know (#169) follow the known ones, by name.
        $unknownLevels = $byLevel->keys()->map(fn (int|string $level): string => (string) $level)->diff(self::LOG_LEVELS)->sort()->values()->all();

        foreach ([...self::LOG_LEVELS, ...$unknownLevels] as $level) {
            $group = $byLevel->get($level);

            if ($group !== null) {
                $levels[] = ['level' => $level, 'count' => $this->estimate($group, fn (array $one): float => $one['count'])];
            }
        }

        $timeline = $series->isEmpty() ? [] : $this->timeline(
            $this->window($project, RuntimeTypeEnum::LogLevel, $range->start($now))->whereIn('dimensions->level', self::ERROR_LOG_LEVELS),
            $range,
            $now,
        );

        return [
            'levels' => $levels,
            'rows' => array_map(
                fn (array $row): array => [...$row, 'channel' => $row['channel'] === '' ? null : $row['channel']],
                $this->table($series, ['level', 'channel'], fn (Collection $group): array => [
                    'count' => $this->estimate($group, fn (array $one): float => $one['count']),
                ]),
            ),
            'timeline' => array_map(fn (array $point): array => ['at' => $point['at'], 'count' => $point['count']], $timeline),
        ];
    }

    /**
     * N+1 query shapes: estimated occurrences (requests or jobs where the shape repeated), the
     * average and the largest number of repeats in one.
     *
     * @param  Collection<int, Series>  $series
     * @return list<array<string, mixed>>
     */
    private function duplicateQueries(Collection $series): array
    {
        return $this->table($series, ['origin', 'name', 'sql', 'connection'], function (Collection $group): array {
            $recorded = $group->sum('count');

            return [
                'count' => $this->estimate($group, fn (array $one): float => $one['count']),
                'averageRepeats' => $recorded > 0 ? round($group->sum(fn (array $one): float => (float) $one['metrics']['totalRepeats']) / $recorded, 1) : null,
                'maxRepeats' => (int) $group->max(fn (array $one): float => (float) $one['metrics']['maxRepeats']),
            ];
        });
    }

    /**
     * The latest sample of each server: its gauges merged by MAX across the entries of that
     * minute (never summed or scaled by sample rate), and whether it runs in a container.
     *
     * @param  Collection<int, Series>  $series
     * @return list<array<string, mixed>>
     */
    private function servers(Project $project, Collection $series): array
    {
        if ($series->isEmpty()) {
            return [];
        }

        $latest = $series->groupBy(fn (array $one): string => $one['dimensions']['server'] ?? '')
            ->map(fn (Collection $group): CarbonImmutable => $group->max('lastAt'));
        $samples = RuntimeAggregate::query()
            ->where('project_id', $project->id)
            ->where('type', RuntimeTypeEnum::Server->value)
            ->whereIn('bucket', $latest->unique()->values()->all())
            ->get();
        $servers = [];

        foreach ($latest->sortKeys() as $server => $lastAt) {
            $gauges = $samples
                ->filter(fn (RuntimeAggregate $sample): bool => ($sample->dimensions['server'] ?? '') === $server && $sample->bucket->equalTo($lastAt))
                ->reduce(fn (array $merged, RuntimeAggregate $sample): array => RuntimeTypeEnum::Server->mergeMetrics($merged, $sample->metrics ?? []), []);

            $servers[] = [
                'server' => (string) $server,
                ...RuntimeTypeEnum::Server->mergeMetrics($gauges, []),
                'lastAt' => $lastAt->toIso8601ZuluString(),
            ];
        }

        return $servers;
    }

    /**
     * Octane workers (#168) per server, Octane server and worker type: estimated requests, tasks,
     * ticks, worker starts and stops and errors; the most memory after a request and its largest
     * growth since a worker's first request, flagged as a possible leak above
     * OCTANE_LEAK_GROWTH_MB; and the requests a worker handled before it stopped (average and
     * most), with `octane.max_requests` from the latest report's laravel-octane-config check
     * (null when that check did not report it).
     *
     * @param  Collection<int, Series>  $series
     * @return array{config: array{server: string|null, maxRequests: int|null}|null, leakGrowthMb: int, rows: list<array<string, mixed>>}
     */
    private function octane(Project $project, Collection $series): array
    {
        $rows = $this->table($series, ['server', 'octaneServer', 'workerType'], function (Collection $group): array {
            $metric = fn (string $name): float => (float) $group->max(fn (array $one): float => (float) $one['metrics'][$name]);
            $counters = [];

            foreach (RuntimeTypeEnum::OCTANE_COUNTERS as $counter) {
                $counters[$counter] = $this->estimate($group, fn (array $one): ?float => $one['metrics'][$counter]);
            }

            // Both are the recorded stops' own counts, so the average is not scaled by sample rate.
            $recordedStops = $group->sum(fn (array $one): float => (float) $one['metrics']['workerStops']);
            $maxGrowthMb = round($metric('maxGrowthMb'), 3);

            return [
                'requests' => $counters['requests'],
                'tasks' => $counters['tasks'],
                'ticks' => $counters['ticks'],
                'workerStarts' => $counters['workerStarts'],
                'workerStops' => $counters['workerStops'],
                'errors' => $this->estimate($group, fn (array $one): float => $one['errorCount']),
                'maxMb' => round($metric('maxMb'), 3),
                'maxGrowthMb' => $maxGrowthMb,
                'isPossibleLeak' => $maxGrowthMb >= self::OCTANE_LEAK_GROWTH_MB,
                'averageRequestsAtStop' => $recordedStops > 0 ? round($group->sum(fn (array $one): float => (float) $one['metrics']['totalRequestsAtStop']) / $recordedStops, 1) : null,
                'maxRequestsAtStop' => $recordedStops > 0 ? (int) $metric('maxRequestsAtStop') : null,
            ];
        }, 'requests');

        return ['config' => $rows === [] ? null : $this->octaneConfig($project), 'leakGrowthMb' => self::OCTANE_LEAK_GROWTH_MB, 'rows' => $rows];
    }

    /**
     * `server` and `maxRequests` of the laravel-octane-config findings of the latest report
     * (`$defs.laravelOctaneConfigDetails`), or null when that report has none.
     *
     * @return array{server: string|null, maxRequests: int|null}|null
     */
    private function octaneConfig(Project $project): ?array
    {
        $details = $this->latestDetails($project, self::OCTANE_CONFIG_CHECK, 'maxRequests');

        return $details === null ? null : [
            'server' => is_string($details['server'] ?? null) ? $details['server'] : null,
            'maxRequests' => is_int($details['maxRequests']) ? $details['maxRequests'] : null,
        ];
    }

    /**
     * Reverb servers (#175) per server and Reverb app id: estimated messages received and sent,
     * connections opened, rejected and pruned, errors, and channels created and removed by name
     * prefix; the peak of the `maxConnections` gauge (not scaled by sample rate). `check` is
     * the latest report's laravel-reverb reading, null when that report has none. It does not
     * depend on the runtime type, which is off by default in the core.
     *
     * @param  Collection<int, Series>  $series
     * @return array{check: array{isReachable: bool, latencyMs: float|null}|null, rows: list<array<string, mixed>>}
     */
    private function reverb(Project $project, Collection $series): array
    {
        $rows = $this->table($series, ['server', 'appId'], function (Collection $group): array {
            $counter = fn (string $name): int => $this->estimate($group, fn (array $one): ?float => $one['metrics'][$name]);
            $channels = fn (string $event): array => array_combine(
                self::REVERB_CHANNEL_PREFIXES,
                array_map(fn (string $prefix): int => $counter($prefix.'Channels'.$event), self::REVERB_CHANNEL_PREFIXES),
            );

            return [
                'messagesReceived' => $counter('messagesReceived'),
                'messagesSent' => $counter('messagesSent'),
                'connectionsOpened' => $counter('connectionsOpened'),
                'connectionsRejected' => $counter('connectionsRejected'),
                'connectionsPruned' => $counter('connectionsPruned'),
                'errors' => $this->estimate($group, fn (array $one): float => $one['errorCount']),
                'channelsCreated' => $channels('Created'),
                'channelsRemoved' => $channels('Removed'),
                'peakConnections' => (int) $group->max(fn (array $one): float => (float) $one['metrics']['maxConnections']),
            ];
        }, 'messagesSent');
        $details = $this->latestDetails($project, self::REVERB_CHECK, 'isReachable');

        return [
            'check' => $details === null ? null : [
                'isReachable' => $details['isReachable'] === true,
                'latencyMs' => is_int($details['latencyMs'] ?? null) || is_float($details['latencyMs'] ?? null) ? (float) $details['latencyMs'] : null,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The `details` of the first finding of a check in the latest report that carry the given
     * key, or null when that report has none.
     *
     * @return array<string, mixed>|null
     */
    private function latestDetails(Project $project, string $checkId, string $key): ?array
    {
        foreach ((array) ($project->latestReport?->report['checks'] ?? []) as $check) {
            if (! is_array($check) || ($check['checkId'] ?? null) !== $checkId) {
                continue;
            }

            foreach ((array) ($check['findings'] ?? []) as $finding) {
                $details = is_array($finding) ? ($finding['details'] ?? null) : null;

                if (is_array($details) && array_key_exists($key, $details)) {
                    return $details;
                }
            }
        }

        return null;
    }

    /**
     * Laravel Cashier webhooks (#178) per provider and event type: estimated received, handled
     * and failed webhooks with the failure rate (%), durations with p95, and the payment signals
     * (failed payments, payments needing action, canceled subscriptions), with their totals over
     * the range. Webhooks of an event type Cashier has no handler for are neither handled nor
     * failed.
     *
     * @param  Collection<int, Series>  $series
     * @return array{totals: array{paymentFailed: int, paymentActionRequired: int, subscriptionCanceled: int}, rows: list<array<string, mixed>>}
     */
    private function billing(Collection $series): array
    {
        $signals = fn (Collection $group): array => [
            'paymentFailed' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['paymentFailedCount']),
            'paymentActionRequired' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['paymentActionRequiredCount']),
            'subscriptionCanceled' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['subscriptionCanceledCount']),
        ];

        return [
            'totals' => $signals($series),
            'rows' => $this->table($series, ['provider', 'eventType'], function (Collection $group) use ($signals): array {
                $totals = $this->totals($group);

                return [
                    ...$totals,
                    'handled' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['handledCount']),
                    'errorRate' => $totals['count'] > 0 ? round($totals['errors'] / $totals['count'] * 100, 1) : null,
                    ...$signals($group),
                ];
            }),
        ];
    }

    /**
     * Laravel authentication (#180) per guard: estimated successful and failed logins with the
     * failure rate (% of login attempts, null without any), lockouts and Sanctum token
     * authentications. `count` is every event recorded and orders the rows.
     *
     * @param  Collection<int, Series>  $series
     * @return list<array<string, mixed>>
     */
    private function auth(Collection $series): array
    {
        return $this->table($series, ['guard'], function (Collection $group): array {
            $logins = $this->estimate($group, fn (array $one): ?float => $one['metrics']['loginCount']);
            $failed = $this->estimate($group, fn (array $one): float => $one['errorCount']);

            return [
                'count' => $this->estimate($group, fn (array $one): float => $one['count']),
                'logins' => $logins,
                'failed' => $failed,
                'failureRate' => $logins + $failed > 0 ? round($failed / ($logins + $failed) * 100, 1) : null,
                'lockouts' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['lockoutCount']),
                'tokenAuthentications' => $this->estimate($group, fn (array $one): ?float => $one['metrics']['tokenAuthenticationCount']),
            ];
        });
    }
}
