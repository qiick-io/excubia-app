<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A muted alert of a project (#114): the whole event (a rule) when `subject` is null, otherwise
 * one issue of it (a finding identity, a scheduled task id, a cache store…). Muted until
 * `until`, or forever when it is null.
 *
 * @property int $id
 * @property int $project_id
 * @property string $event
 * @property string|null $subject
 * @property string|null $label
 * @property Carbon|null $until
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
#[Fillable(['project_id', 'event', 'subject', 'label', 'until', 'user_id'])]
class AlertMute extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Mutes still in force.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $active) => $active->whereNull('until')->orWhere('until', '>', Date::now()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }
}
