<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * One finding of a project across reports (#18), keyed by core's `fingerprint`. `finding` is the
 * report row (with `checkName`) of the last report that had it. A dismissal is active while
 * `dismissed_at` is set and `dismissed_until` is empty or today or later.
 *
 * @property int $id
 * @property int $project_id
 * @property string $fingerprint
 * @property string $check_id
 * @property string $severity
 * @property FindingStatusEnum $status
 * @property array<string, mixed> $finding
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property int $reopened_count
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $dismissed_until
 * @property string|null $dismissed_reason
 * @property int|null $dismissed_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['fingerprint', 'check_id', 'severity', 'status', 'finding', 'first_seen_at', 'last_seen_at', 'resolved_at', 'reopened_count', 'dismissed_at', 'dismissed_until', 'dismissed_reason', 'dismissed_by_user_id'])]
class Finding extends Model
{
    /**
     * Findings with an active dismissal.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function dismissed(Builder $query): void
    {
        $query->whereNotNull('dismissed_at')
            ->where(fn (Builder $query) => $query->whereNull('dismissed_until')->orWhereDate('dismissed_until', '>=', Date::today()));
    }

    /**
     * Open findings without an active dismissal: what alerts and the digest count.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', FindingStatusEnum::Open->value)
            ->where(fn (Builder $query) => $query->whereNull('dismissed_at')->orWhereDate('dismissed_until', '<', Date::today()));
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The history, oldest first.
     *
     * @return HasMany<FindingEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FindingEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FindingStatusEnum::class,
            'finding' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reopened_count' => 'integer',
            'dismissed_at' => 'datetime',
            'dismissed_until' => 'date',
        ];
    }
}
