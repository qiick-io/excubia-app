<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A maintenance window of a project (#114) during which its alerts are suppressed:
 * - `once`: from `starts_at` to `ends_at` (stored in UTC; entered in `timezone`);
 * - `weekly`: on the ISO `weekdays` (1 Monday … 7 Sunday) from `start_time` (HH:MM, local to
 *   `timezone`) for `duration_minutes`, possibly past midnight.
 * AlertSuppressionService::isWindowActive() decides whether it is in force.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $recurrence once|weekly
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property list<int>|null $weekdays
 * @property string|null $start_time
 * @property int|null $duration_minutes
 * @property string $timezone
 */
#[Fillable(['project_id', 'name', 'recurrence', 'starts_at', 'ends_at', 'weekdays', 'start_time', 'duration_minutes', 'timezone'])]
class MaintenanceWindow extends Model
{
    public const ONCE = 'once';

    public const WEEKLY = 'weekly';

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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'weekdays' => 'array',
            'duration_minutes' => 'integer',
        ];
    }
}
