<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduledTaskStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One task of a project's schedule inventory (#110), from the `schedule` field of its latest
 * report push (excubia-core `docs/report-schema.json` `$defs.scheduledTask`), keyed by `taskId`.
 * The status columns are written by `excubia:evaluate-schedules`; `created_at` is when the task
 * first appeared, so due times before it are never judged.
 *
 * @property int $id
 * @property int $project_id
 * @property string $task_id
 * @property string $task
 * @property string $expression
 * @property string $timezone
 * @property bool $without_overlapping
 * @property bool $on_one_server
 * @property bool $runs_in_maintenance_mode
 * @property list<string> $environments
 * @property int $late_after_minutes
 * @property int $position
 * @property ScheduledTaskStatusEnum $status
 * @property bool $is_low_confidence
 * @property Carbon|null $last_due_at
 * @property Carbon|null $next_due_at
 * @property Carbon|null $last_run_at
 * @property string|null $last_outcome
 * @property Carbon|null $status_changed_at
 * @property ScheduledTaskStatusEnum|null $alerted_status
 * @property Carbon|null $evaluated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'task_id', 'task', 'expression', 'timezone', 'without_overlapping', 'on_one_server', 'runs_in_maintenance_mode', 'environments', 'late_after_minutes', 'position'])]
class ScheduledTask extends Model
{
    /** The task excubia-core schedules every minute: the "scheduler alive" signal. */
    public const HEARTBEAT_TASK = 'artisan excubia:heartbeat';

    /**
     * Column defaults, so a new model has them before it is refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'is_low_confidence' => false,
    ];

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
            'without_overlapping' => 'boolean',
            'on_one_server' => 'boolean',
            'runs_in_maintenance_mode' => 'boolean',
            'environments' => 'array',
            'late_after_minutes' => 'integer',
            'position' => 'integer',
            'status' => ScheduledTaskStatusEnum::class,
            'is_low_confidence' => 'boolean',
            'last_due_at' => 'datetime',
            'next_due_at' => 'datetime',
            'last_run_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'alerted_status' => ScheduledTaskStatusEnum::class,
            'evaluated_at' => 'datetime',
        ];
    }
}
