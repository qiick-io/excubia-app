<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuntimeTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One minute of one runtime series of a project (#48, #111): one of the 17 core runtime types
 * per its dimensions (and the batch's release, when sent). Counts are the recorded events; the
 * estimated real count is `count / sample_rate`. `total_ms` and `max_ms` are null for types that
 * are not timed; `metrics` holds the type-specific metrics of `RuntimeTypeEnum::metrics()`
 * (memory, cache counters, repeats, server gauges and `isContainer`, health status, severity,
 * finding count and fingerprints), `histogram` the optional 12 latency counts.
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
 * @property string|null $release
 * @property float|null $total_ms
 * @property float|null $max_ms
 * @property array<string, mixed>|null $metrics
 * @property list<int>|null $histogram
 */
#[Fillable(['project_id', 'type', 'bucket', 'sample_rate', 'dimension_key', 'dimensions', 'count', 'error_count', 'total_ms', 'max_ms', 'release', 'metrics', 'histogram'])]
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
            'metrics' => 'array',
            'histogram' => 'array',
        ];
    }
}
