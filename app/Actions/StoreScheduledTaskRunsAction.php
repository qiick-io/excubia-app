<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;

/**
 * Records the runs of scheduled tasks (#110) from the `scheduledTask` aggregates of a runtime
 * batch, per `taskId`, minute bucket and outcome: counts add up across batches, the sample rate
 * keeps the lowest. Aggregates without a `taskId` (cores before #146) cannot be matched with the
 * schedule and are left out, and so are outcomes the schedule monitoring does not know (#169;
 * the runtime aggregate keeps them). Called inside the runtime batch transaction, so a repeated batch
 * records nothing.
 */
class StoreScheduledTaskRunsAction
{
    /**
     * @param  list<array<string, mixed>>  $aggregates  validated by StorePushedRuntimeRequest
     */
    public function execute(Project $project, array $aggregates): void
    {
        foreach ($aggregates as $aggregate) {
            if (($aggregate['type'] ?? null) !== RuntimeTypeEnum::ScheduledTask->value
                || ! is_string($aggregate['taskId'] ?? null)
                || ! in_array($aggregate['outcome'] ?? null, ScheduledTaskRun::OUTCOMES, true)) {
                continue;
            }

            $identity = [
                'project_id' => $project->id,
                'task_id' => $aggregate['taskId'],
                'bucket' => CarbonImmutable::parse((string) $aggregate['bucket'])->utc(),
                'outcome' => (string) $aggregate['outcome'],
            ];
            $sampleRate = round((float) $aggregate['sampleRate'], 4);
            $existing = ScheduledTaskRun::query()->where($identity)->lockForUpdate()->first();

            if ($existing === null) {
                ScheduledTaskRun::query()->create([...$identity, 'count' => (int) $aggregate['count'], 'sample_rate' => $sampleRate]);

                continue;
            }

            $existing->forceFill([
                'count' => $existing->count + (int) $aggregate['count'],
                'sample_rate' => min($existing->sample_rate, $sampleRate),
            ])->save();
        }
    }
}
