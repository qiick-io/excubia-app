<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeverityEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $release release identifier of the deployed code (#106)
 * @property Carbon|null $deployed_at set by `excubia push --deploy`: the report is a deploy marker
 * @property SeverityEnum|null $worst_severity
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $info_count
 * @property string|null $worst_check_status worst status of the checks that ran: fail, warn or pass (#293)
 * @property int $checks_count checks that ran (not skipped)
 * @property int $failed_checks_count
 * @property array<string, mixed> $report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['batch_id', 'received_at', 'generated_at', 'release', 'deployed_at', 'worst_severity', 'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count', 'worst_check_status', 'checks_count', 'failed_checks_count', 'report'])]
class HealthReport extends Model
{
    /**
     * Reports sent by `excubia push --deploy`, the project's deploys.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function deploys(Builder $query): void
    {
        $query->whereNotNull('deployed_at');
    }

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
            'deployed_at' => 'datetime',
            'worst_severity' => SeverityEnum::class,
            'report' => 'array',
        ];
    }
}
