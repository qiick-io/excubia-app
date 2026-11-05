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
 * Stores a runtime batch pushed by a project (#48). Aggregates are additive, as
 * `docs/runtime-schema.json` asks: for the same type, minute bucket, sample rate and dimensions
 * the counts, errors and total milliseconds add up and the larger max wins, also across batches.
 * A repeated `batchId` stores nothing and returns false.
 *
 * ponytail: one read and one write per aggregate inside the transaction; batch the upserts if
 * large fleets push thousands of series a minute.
 */
class StorePushedRuntimeAction
{
    /**
     * @param  list<array<string, mixed>>  $aggregates  validated by StorePushedRuntimeRequest
     * @return bool true when the batch was new
     */
    public function execute(Project $project, string $batchId, array $aggregates): bool
    {
        try {
            return DB::transaction(function () use ($project, $batchId, $aggregates): bool {
                PushedBatch::query()->create(['project_id' => $project->id, 'type' => 'runtime', 'batch_id' => $batchId, 'received_at' => Date::now()]);

                foreach ($aggregates as $aggregate) {
                    $this->add($project, $aggregate);
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
    private function add(Project $project, array $aggregate): void
    {
        $type = RuntimeTypeEnum::from((string) $aggregate['type']);
        $dimensions = [];

        foreach ($type->dimensions() as $dimension) {
            $dimensions[$dimension] = (string) $aggregate[$dimension];
        }

        $identity = [
            'project_id' => $project->id,
            'type' => $type->value,
            'bucket' => CarbonImmutable::parse((string) $aggregate['bucket'])->utc(),
            'sample_rate' => round((float) $aggregate['sampleRate'], 4),
            'dimension_key' => hash('sha256', (string) json_encode($dimensions)),
        ];

        $existing = RuntimeAggregate::query()->where($identity)->lockForUpdate()->first();

        if ($existing === null) {
            RuntimeAggregate::query()->create([
                ...$identity,
                'dimensions' => $dimensions,
                'count' => (int) $aggregate['count'],
                'error_count' => (int) $aggregate['errorCount'],
                'total_ms' => (float) $aggregate['totalMs'],
                'max_ms' => (float) $aggregate['maxMs'],
            ]);

            return;
        }

        $existing->forceFill([
            'count' => $existing->count + (int) $aggregate['count'],
            'error_count' => $existing->error_count + (int) $aggregate['errorCount'],
            'total_ms' => $existing->total_ms + (float) $aggregate['totalMs'],
            'max_ms' => max($existing->max_ms, (float) $aggregate['maxMs']),
        ])->save();
    }
}
