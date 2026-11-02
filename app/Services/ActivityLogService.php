<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Builds the filtered, newest-first activity log query of the activity log page.
 */
class ActivityLogService
{
    /**
     * @param  array{search: string, user_ids: list<int>, event: string, log_name: string, date_from: string, date_to: string}  $filters
     * @return Builder<Activity>
     */
    public function filteredQuery(array $filters): Builder
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->latest('id');

        if ($filters['user_ids'] !== []) {
            $query->where('causer_type', (new User)->getMorphClass())
                ->whereIn('causer_id', $filters['user_ids']);
        }

        if ($filters['event'] !== '') {
            $query->forEvent($filters['event']);
        }

        if ($filters['log_name'] !== '') {
            $query->inLog($filters['log_name']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['search'] !== '') {
            $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $term = '%'.$filters['search'].'%';

            $query->where(function (Builder $inner) use ($operator, $term): void {
                $inner->where('description', $operator, $term)
                    ->orWhere('subject_type', $operator, $term)
                    ->orWhere('event', $operator, $term);
            });
        }

        return $query;
    }
}
