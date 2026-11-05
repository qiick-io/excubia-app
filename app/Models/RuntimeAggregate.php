<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuntimeTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One minute of one runtime series of a project (#48): requests per route, method and status
 * class, slow queries per SQL, jobs, scheduled tasks or outgoing HTTP per host. Counts are the
 * recorded events; the estimated real count is `count / sample_rate`.
 *
 * @property int $id
 * @property int $project_id
 * @property RuntimeTypeEnum $type
 * @property Carbon $bucket
 * @property float $sample_rate
 * @property string $dimension_key
 * @property array<string, string> $dimensions
 * @property int $count
 * @property int $error_count
 * @property float $total_ms
 * @property float $max_ms
 */
#[Fillable(['project_id', 'type', 'bucket', 'sample_rate', 'dimension_key', 'dimensions', 'count', 'error_count', 'total_ms', 'max_ms'])]
class RuntimeAggregate extends Model
{
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
            'dimensions' => 'array',
            'count' => 'integer',
            'error_count' => 'integer',
            'total_ms' => 'float',
            'max_ms' => 'float',
        ];
    }
}
