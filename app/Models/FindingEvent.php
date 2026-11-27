<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingEventEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a finding's history (#18): the report that opened, resolved or reopened it, or the
 * user who dismissed it (with reason and expiry), removed the dismissal, or assigned it to
 * `assigned_user_id` (#254).
 *
 * @property int $id
 * @property int $finding_id
 * @property FindingEventEnum $event
 * @property int|null $health_report_id
 * @property int|null $user_id
 * @property string|null $reason
 * @property Carbon|null $until
 * @property Carbon $occurred_at
 * @property int|null $assigned_user_id
 */
#[Fillable(['event', 'health_report_id', 'user_id', 'reason', 'until', 'occurred_at', 'assigned_user_id'])]
class FindingEvent extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * @return BelongsTo<HealthReport, $this>
     */
    public function healthReport(): BelongsTo
    {
        return $this->belongsTo(HealthReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user the finding was assigned to by this event (#254).
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => FindingEventEnum::class,
            'until' => 'date',
            'occurred_at' => 'datetime',
        ];
    }
}
