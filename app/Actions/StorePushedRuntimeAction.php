<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
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
 * the rest of its batch. A repeated `batchId` stores nothing and returns false.
 *
 * ponytail: one read and one write per aggregate inside the transaction; batch the upserts if
 * large fleets push thousands of series a minute.
 */
class StorePushedRuntimeAction
{
    /**
     * @param  list<array<string, mixed>>  $aggregates  validated by StorePushedRuntimeRequest
     * @param  string|null  $release  the batch's `runtime.release`, an extra dimension
     * @return bool true when the batch was new
     */
    public function execute(Project $project, string $batchId, array $aggregates, ?string $release = null): bool
    {
        try {
            return DB::transaction(function () use ($project, $batchId, $aggregates, $release): bool {
                PushedBatch::query()->create(['project_id' => $project->id, 'type' => 'runtime', 'batch_id' => $batchId, 'received_at' => Date::now()]);
                $skipped = 0;

                foreach ($aggregates as $aggregate) {
                    $type = RuntimeTypeEnum::tryFrom((string) ($aggregate['type'] ?? ''));

                    if ($type === null) {
                        $skipped++;

                        continue;
                    }

                    $this->add($project, $type, $aggregate, $release);
                }

                if ($skipped > 0) {
                    // A query increment: no model event, so no activity log entry per push.
                    Project::query()->whereKey($project->id)->increment('skipped_runtime_aggregates', $skipped);
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $aggregate
     */
    private function add(Project $project, RuntimeTypeEnum $type, array $aggregate, ?string $release): void
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

        $identity = [
            'project_id' => $project->id,
            'type' => $type->value,
            'bucket' => CarbonImmutable::parse((string) $aggregate['bucket'])->utc(),
            'sample_rate' => round((float) $aggregate['sampleRate'], 4),
            'dimension_key' => hash('sha256', $identityJson),
        ];

        $metrics = $this->metrics($type, $aggregate);
        $histogram = $this->histogram($type, $aggregate);
        $existing = RuntimeAggregate::query()->where($identity)->lockForUpdate()->first();

        if ($existing === null) {
            RuntimeAggregate::query()->create([
                ...$identity,
                'dimensions' => $dimensions,
                'release' => $release,
                'count' => (int) $aggregate['count'],
                'error_count' => (int) $aggregate['errorCount'],
                'total_ms' => $type->isTimed() ? (float) $aggregate['totalMs'] : null,
                'max_ms' => $type->isTimed() ? (float) $aggregate['maxMs'] : null,
                'metrics' => $metrics,
                'histogram' => $histogram,
            ]);

            return;
        }

        $existing->forceFill([
            'count' => $existing->count + (int) $aggregate['count'],
            'error_count' => $existing->error_count + (int) $aggregate['errorCount'],
            'total_ms' => $type->isTimed() ? (float) $existing->total_ms + (float) $aggregate['totalMs'] : null,
            'max_ms' => $type->isTimed() ? max((float) $existing->max_ms, (float) $aggregate['maxMs']) : null,
            'metrics' => $metrics === null ? null : $type->mergeMetrics($existing->metrics ?? [], $metrics),
            'histogram' => $this->mergeHistograms($existing->histogram, $histogram),
        ])->save();
    }

    /**
     * The type-specific metrics of an entry: numbers or null, `isContainer` a boolean (missing
     * is false). Null for a type without any.
     *
     * @param  array<string, mixed>  $aggregate
     * @return array<string, int|float|bool|null>|null
     */
    private function metrics(RuntimeTypeEnum $type, array $aggregate): ?array
    {
        $metrics = [];

        foreach ($type->metrics() as $metric => $merge) {
            $value = $aggregate[$metric] ?? null;
            $metrics[$metric] = match (true) {
                $merge === 'or' => (bool) $value,
                is_int($value), is_float($value) => $value,
                is_numeric($value) => (float) $value,
                default => null,
            };
        }

        return $metrics === [] ? null : $metrics;
    }

    /**
     * The entry's latency histogram as integers, or null when it has none or not 12 buckets.
     *
     * @param  array<string, mixed>  $aggregate
     * @return list<int>|null
     */
    private function histogram(RuntimeTypeEnum $type, array $aggregate): ?array
    {
        $histogram = $aggregate['histogram'] ?? null;

        if (! $type->isTimed() || ! is_array($histogram) || count($histogram) !== RuntimeTypeEnum::HISTOGRAM_LENGTH) {
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
