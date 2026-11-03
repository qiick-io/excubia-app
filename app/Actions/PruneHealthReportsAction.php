<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\HealthReport;
use App\Models\Project;

/**
 * Keeps the newest `$keep` reports of every project (by `received_at`) and deletes the rest.
 */
class PruneHealthReportsAction
{
    /**
     * @return int number of deleted reports
     */
    public function execute(int $keep): int
    {
        $deleted = 0;

        Project::query()->select('id')->each(function (Project $project) use ($keep, &$deleted): void {
            $keptIds = $project->healthReports()
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit($keep)
                ->pluck('id');

            $deleted += HealthReport::query()
                ->where('project_id', $project->id)
                ->whereNotIn('id', $keptIds)
                ->delete();
        });

        return $deleted;
    }
}
