<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Spatie activity row with an optional organisation perimeter (fleet baseline).
 *
 * Excubia has no organisations yet: `organisation_id` stays null.
 *
 * @property string|null $organisation_id
 */
class Activity extends SpatieActivity
{
    /**
     * Restrict the query to one organisation.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganisation(Builder $query, string $organisationId): Builder
    {
        return $query->where('organisation_id', $organisationId);
    }
}
