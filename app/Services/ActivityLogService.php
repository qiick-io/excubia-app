<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the filtered, newest-first activity log query of the activity log page.
 *
 * Entries about a project are kept only when the viewer may see that project (#152): a
 * non-admin holding `can-show-activity-logs` does not see changes to projects restricted to
 * admins. Entries about deleted projects are hidden from non-admins too, since their
 * visibility can no longer be checked.
 *
 * Morph ids are `string(64)` (fleet): comparisons to bigint primary keys cast to text so
 * PostgreSQL accepts the join (MySQL and SQLite coerce silently).
 */
class ActivityLogService
{
    public function __construct(private EffectivePermissionResolverService $permissions) {}

    /**
     * @param  array{search: string, user_ids: list<int>, event: string, log_name: string, date_from: string, date_to: string}  $filters
     * @return Builder<Activity>
     */
    public function filteredQuery(array $filters, User $viewer): Builder
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->latest('id');

        if (! $this->permissions->isAdmin($viewer)) {
            $visibleProjects = Project::query()->visibleTo($viewer);
            $visibleProjectIds = match ($this->connectionDriver($query)) {
                'mysql', 'mariadb' => $visibleProjects->selectRaw('CAST(id AS CHAR)'),
                default => $visibleProjects->selectRaw('CAST(id AS TEXT)'),
            };

            $query->where(fn (Builder $inner) => $inner->whereNull('subject_type')
                ->orWhere('subject_type', '!=', (new Project)->getMorphClass())
                ->orWhereIn('subject_id', $visibleProjectIds));
        }

        if ($filters['user_ids'] !== []) {
            $query->where('causer_type', (new User)->getMorphClass())
                ->whereIn('causer_id', array_map(strval(...), $filters['user_ids']));
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

    /**
     * Database driver name for the activity connection.
     *
     * @param  Builder<Activity>  $query
     */
    private function connectionDriver(Builder $query): string
    {
        $connection = $query->getModel()->getConnection();

        return $connection->getDriverName();
    }
}
