<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuntimeTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One hour of one runtime type of a project at one sample rate (#137): the sums of its
 * `RuntimeAggregate` minutes, filled at ingest by StoreRuntimeRollupsAction, so the dashboard
 * totals read one row per hour. `bucket` is the start of the hour; `total_ms` is null for the
 * types that are not timed.
 *
 * @property int $id
 * @property int $project_id
 * @property RuntimeTypeEnum $type
 * @property Carbon $bucket
 * @property float $sample_rate
 * @property int $count
 * @property int $error_count
 * @property float|null $total_ms
 */
#[Fillable(['project_id', 'type', 'bucket', 'sample_rate', 'count', 'error_count', 'total_ms'])]
class RuntimeHourlyRollup extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RuntimeTypeEnum::class,
            'bucket' => 'datetime',
            'sample_rate' => 'float',
            'count' => 'integer',
            'error_count' => 'integer',
            'total_ms' => 'float',
        ];
    }
}
