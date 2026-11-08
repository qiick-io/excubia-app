<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuntimeTypeEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Stores a runtime batch pushed by a project (#48, #111). Aggregates are additive, as
 * `docs/runtime-schema.json` asks: for the same type, minute bucket, sample rate, dimensions and
 * release the counts, errors and totals add up, maxima and server gauges keep the larger value,
 * `isContainer` is true when any entry is, and histograms add up element by element (none when
 * only one side has one), also across batches. Entries of a type this app does not know are
 * skipped and counted on the project (`skipped_runtime_aggregates`), so a newer core never loses
 * the rest of its batch; so are `health` samples with a `status` or `severity` the app does not
 * know, which it cannot rank (#169). Other enumerated values this app does not know (an `ai`
 * `operation`, a log `level`, an `outcome`…) are stored as any other dimension. A repeated `batchId` stores nothing and returns false. The same
 * transaction adds the entries to their hourly rollups (#137), which the dashboard totals read,
 * and the `ai` entries to the daily AI usage (#129).
 * After a new batch with `health` samples (#115) the health alerts are evaluated.
 *
 * The entries are merged in PHP first, then the series the batch touches are read in one locked
 * query and the new ones written in one insert per chunk (#195): one read and one write per entry
 * held SQLite's single write lock for most of a push.
 */
class StorePushedRuntimeAction
{
    /**
     * Tries of the store transaction: concurrent pushes can deadlock on MySQL's gap locks (or,
     * past the busy timeout, find SQLite locked), and the whole push is then rolled back and
     * run again (#195).
     */
    private const TRANSACTION_ATTEMPTS = 3;

    /** The columns merged() adds an entry to. */
    private const MERGED = ['count', 'error_count', 'total_ms', 'max_ms', 'metrics', 'histogram'];

    public function __construct(
        private StoreScheduledTaskRunsAction $scheduledTaskRuns,
        private StoreRuntimeRollupsAction $rollups,
        private EvaluateHealthAlertsAction $healthAlerts,
        private StoreAiUsageDaysAction $aiUsageDays,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $aggregates  validated by StorePushedRuntimeRequest
     * @param  string|null  $release  the batch's `runtime.release`, an extra dimension
     * @param  string|null  $environment  the batch's `runtime.environment` (core #203): kept on the project when it matches `Project::ENVIRONMENT_PATTERN`, dropped otherwise
     * @return bool true when the batch was new
     */
    public function execute(Project $project, string $batchId, array $aggregates, ?string $release = null, ?string $environment = null): bool
    {
        try {
            $isNew = DB::transaction(function () use ($project, $batchId, $aggregates, $release, $environment): bool {
                PushedBatch::query()->create(['project_id' => $project->id, 'type' => 'runtime', 'batch_id' => $batchId, 'received_at' => Date::now()]);

                if ($environment !== null && $environment !== $project->environment && preg_match(Project::ENVIRONMENT_PATTERN, $environment) === 1) {
                    // A query update: no model event, so no activity log entry per push.
                    Project::query()->whereKey($project->id)->update(['environment' => $environment]);
                }

                $skipped = 0;
                $minutes = [];
                $series = [];

                foreach ($aggregates as $aggregate) {
                    $type = RuntimeTypeEnum::tryFrom((string) ($aggregate['type'] ?? ''));

                    if ($type === null || $this->isUnreadable($type, $aggregate)) {
                        $skipped++;

                        continue;
                    }

                    [$key, $entry, $minutes[]] = $this->entry($project, $type, $aggregate, $release);
                    $series[$key] = isset($series[$key]) ? [...$series[$key], ...$this->merged($type, $series[$key], $entry)] : $entry;
                }

                $this->store($project, $series);
                $this->rollups->execute($minutes);

                // Daily AI usage per provider and model (#129), for the monthly budget and the digest.
                $this->aiUsageDays->execute($project, array_values(array_filter($aggregates, fn (array $aggregate): bool => ($aggregate['type'] ?? null) === RuntimeTypeEnum::Ai->value)));

                // Runs per scheduled task for schedule monitoring (#110), known outcomes only.
                $this->scheduledTaskRuns->execute($project, $aggregates);

                if ($skipped > 0) {
                    // A query increment: no model event, so no activity log entry per push.
                    Project::query()->whereKey($project->id)->increment('skipped_runtime_aggregates', $skipped);
                }

                return true;
            }, attempts: self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        // Health samples (#115) move the project's live status and may alert, once committed.
        if (in_array(RuntimeTypeEnum::Health->value, array_column($aggregates, 'type'), true)) {
            $this->healthAlerts->execute($project);
        }

        return $isNew;
    }

    /**
     * True for a `health` sample whose `status` or `severity` this app does not know: it cannot
     * be ranked against the others, so it is skipped and counted instead of stored (#169).
     *
     * @param  array<string, mixed>  $aggregate
     */
    private function isUnreadable(RuntimeTypeEnum $type, array $aggregate): bool
    {
        return $type === RuntimeTypeEnum::Health
            && (! in_array($aggregate['status'] ?? null, RuntimeTypeEnum::HEALTH_STATUSES, true)
                || ! in_array($aggregate['severity'] ?? null, SeverityEnum::values(), true));
    }

    /**
     * One entry as the row of its minute series, keyed by the series identity.
     *
     * @param  array<string, mixed>  $aggregate
     * @return array{0: string, 1: array<string, mixed>, 2: array{project_id: int, type: string, bucket: CarbonImmutable, sample_rate: float, count: int, error_count: int, total_ms: float|null}} the key, the row, and the entry for its hourly rollup
     */
    private function entry(Project $project, RuntimeTypeEnum $type, array $aggregate, ?string $release): array
    {
        $dimensions = [];

        foreach ($type->dimensions() as $dimension) {
            $dimensions[$dimension] = (string) $aggregate[$dimension];
        }

        foreach ($type->optionalDimensions() as $dimension) {
            if (isset($aggregate[$dimension])) {
                $dimensions[$dimension] = (string) $aggregate[$dimension];
            }
        }

        // Without a release the key is the one of the beta.1 series, so they keep merging.
        $identityJson = (string) json_encode($dimensions).($release === null ? '' : "\0".$release);

        // A Scout search (#184) has no duration: only entries with both totals are timed.
        $isTimed = $type->isTimed() && isset($aggregate['totalMs'], $aggregate['maxMs']);

        $row = [
            'project_id' => $project->id,
            'type' => $type,
            'bucket' => CarbonImmutable::parse((string) $aggregate['bucket'])->utc(),
            'sample_rate' => round((float) $aggregate['sampleRate'], 4),
            'dimension_key' => hash('sha256', $identityJson),
            'dimensions' => $dimensions,
            'release' => $release,
            'count' => (int) $aggregate['count'],
            'error_count' => (int) $aggregate['errorCount'],
            'total_ms' => $isTimed ? (float) $aggregate['totalMs'] : null,
            'max_ms' => $isTimed ? (float) $aggregate['maxMs'] : null,
            'metrics' => $this->metrics($type, $aggregate),
            'histogram' => $isTimed ? $this->histogram($aggregate) : null,
        ];

        $minute = [
            'project_id' => $project->id,
            'type' => $type->value,
            'bucket' => $row['bucket'],
            'sample_rate' => $row['sample_rate'],
            'count' => $row['count'],
            'error_count' => $row['error_count'],
            'total_ms' => $row['total_ms'],
        ];

        return [self::seriesKey($type, $row['bucket'], $row['sample_rate'], $row['dimension_key']), $row, $minute];
    }

    /**
     * Adds the batch's series to the stored ones: the stored series are read in one locked
     * query and merged, the new ones inserted in chunks below the bound-parameter limits.
     *
     * @param  array<string, array<string, mixed>>  $series  rows by series key, see entry()
     */
    private function store(Project $project, array $series): void
    {
        $stored = [];

        foreach (array_chunk($series, 500, true) as $chunk) {
            $found = RuntimeAggregate::query()
                ->where('project_id', $project->id)
                // Type and bucket keep the lookup on the identity index, whatever the retention.
                ->whereIn('type', array_unique(array_map(fn (array $row): string => $row['type']->value, $chunk)))
                ->whereIn('bucket', array_unique(array_column($chunk, 'bucket')))
                ->whereIn('dimension_key', array_unique(array_column($chunk, 'dimension_key')))
                ->lockForUpdate()
                ->get();

            foreach ($found as $aggregate) {
                $stored[self::seriesKey($aggregate->type, $aggregate->bucket, $aggregate->sample_rate, $aggregate->dimension_key)] = $aggregate;
            }
        }

        $now = Date::now();
        $inserts = [];

        foreach ($series as $key => $row) {
            $existing = $stored[$key] ?? null;

            if ($existing === null) {
                $inserts[] = [
                    ...$row,
                    'type' => $row['type']->value,
                    'dimensions' => json_encode($row['dimensions']),
                    'metrics' => $row['metrics'] === null ? null : json_encode($row['metrics']),
                    'histogram' => $row['histogram'] === null ? null : json_encode($row['histogram']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            $existing->forceFill($this->merged($row['type'], $existing->only(self::MERGED), $row))->save();
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            RuntimeAggregate::query()->insert($chunk);
        }
    }

    /**
     * Merges an entry into a row of the same series: counts, errors and totals add up, the
     * maximum keeps the larger value (an untimed entry leaves both as they are), metrics merge
     * by their type's rules and histograms add up element by element.
     *
     * @param  array<string, mixed>  $stored  the MERGED columns of a stored row, or a row of entry()
     * @param  array<string, mixed>  $incoming  a row of entry()
     * @return array<string, mixed> the MERGED columns
     */
    private function merged(RuntimeTypeEnum $type, array $stored, array $incoming): array
    {
        $isTimed = $incoming['total_ms'] !== null;

        return [
            'count' => $stored['count'] + $incoming['count'],
            'error_count' => $stored['error_count'] + $incoming['error_count'],
            'total_ms' => $isTimed ? (float) $stored['total_ms'] + $incoming['total_ms'] : $stored['total_ms'],
            'max_ms' => $isTimed ? max((float) $stored['max_ms'], $incoming['max_ms']) : $stored['max_ms'],
            'metrics' => $incoming['metrics'] === null ? null : $type->mergeMetrics($stored['metrics'] ?? [], $incoming['metrics']),
            'histogram' => $this->mergeHistograms($stored['histogram'], $incoming['histogram']),
        ];
    }

    /** The identity of a minute series within a project, as the unique index has it. */
    private static function seriesKey(RuntimeTypeEnum $type, DateTimeInterface $bucket, float $sampleRate, string $dimensionKey): string
    {
        return implode('|', [$type->value, $bucket->getTimestamp(), sprintf('%.4F', $sampleRate), $dimensionKey]);
    }

    /**
     * The type-specific metrics of an entry: numbers or null, `isContainer` a boolean (missing
     * is false), the health `status` and `severity` strings and `fingerprints` a sorted list.
     * Null for a type without any.
     *
     * @param  array<string, mixed>  $aggregate
     * @return array<string, mixed>|null
     */
    private function metrics(RuntimeTypeEnum $type, array $aggregate): ?array
    {
        $metrics = [];

        foreach ($type->metrics() as $metric => $merge) {
            $value = $aggregate[$metric] ?? null;
            $metrics[$metric] = match (true) {
                $merge === 'or' => (bool) $value,
                $merge === 'status', $merge === 'severity' => is_string($value) ? $value : null,
                $merge === 'union' => $type->mergeMetrics([], [$metric => $value])[$metric],
                is_int($value), is_float($value) => $value,
                is_numeric($value) => (float) $value,
                default => null,
            };
        }

        return $metrics === [] ? null : $metrics;
    }

    /**
     * A timed entry's latency histogram as integers, or null when it has none or not 12 buckets.
     *
     * @param  array<string, mixed>  $aggregate
     * @return list<int>|null
     */
    private function histogram(array $aggregate): ?array
    {
        $histogram = $aggregate['histogram'] ?? null;

        if (! is_array($histogram) || count($histogram) !== RuntimeTypeEnum::HISTOGRAM_LENGTH) {
            return null;
        }

        return array_map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, array_values($histogram));
    }

    /**
     * Element-wise sum; none when only one side has a histogram, so it never disagrees with count.
     *
     * @param  list<int>|null  $existing
     * @param  list<int>|null  $incoming
     * @return list<int>|null
     */
    private function mergeHistograms(?array $existing, ?array $incoming): ?array
    {
        if ($existing === null || $incoming === null || count($existing) !== count($incoming)) {
            return null;
        }

        return array_map(fn (int $left, int $right): int => $left + $right, $existing, $incoming);
    }
}
