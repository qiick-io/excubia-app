<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The runs of one scheduled task (#110) in one UTC minute with one outcome (`finished`, `failed`
 * or `skipped`), from the runtime `scheduledTask` aggregates that carry a `taskId`. `count` is
 * the recorded runs; `sample_rate` the lowest rate merged in (below 1, a missing run proves
 * little).
 *
 * @property int $id
 * @property int $project_id
 * @property string $task_id
 * @property Carbon $bucket
 * @property string $outcome
 * @property int $count
 * @property float $sample_rate
 */
#[Fillable(['project_id', 'task_id', 'bucket', 'outcome', 'count', 'sample_rate'])]
class ScheduledTaskRun extends Model
{
    /** Outcomes of a scheduled-task run that the schedule monitoring reads, as excubia-core sends them. */
    public const OUTCOMES = ['finished', 'failed', 'skipped'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket' => 'datetime',
            'count' => 'integer',
            'sample_rate' => 'float',
        ];
    }
}
