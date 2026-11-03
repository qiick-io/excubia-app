<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeverityEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One report pushed by a project with `excubia push`. `report` is the excubia-core JSON report
 * as received; the severity columns count the findings that warn or fail.
 *
 * @property int $id
 * @property int $project_id
 * @property string $batch_id
 * @property Carbon $received_at
 * @property Carbon $generated_at
 * @property SeverityEnum|null $worst_severity
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $info_count
 * @property array<string, mixed> $report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['batch_id', 'received_at', 'generated_at', 'worst_severity', 'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count', 'report'])]
class HealthReport extends Model
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
            'received_at' => 'datetime',
            'generated_at' => 'datetime',
            'worst_severity' => SeverityEnum::class,
            'report' => 'array',
        ];
    }
}
