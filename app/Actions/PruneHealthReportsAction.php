<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the newest `$keep` reports of every project (by `received_at`) and deletes the rest.
 */
class PruneHealthReportsAction
{
    public function __construct(private readonly PruneRowsAction $pruneRows) {}

    /**
     * @return int number of deleted reports
     */
    public function execute(int $keep): int
    {
        $deleted = 0;

        Project::query()->select('id')->each(function (Project $project) use ($keep, &$deleted): void {
            $oldReports = $project->healthReports()->getQuery();

            if ($keep > 0) {
                $oldestKept = $project->healthReports()
                    ->orderByDesc('received_at')
                    ->orderByDesc('id')
                    ->offset($keep - 1)
                    ->first(['received_at', 'id']);

                if ($oldestKept === null) {
                    return;
                }

                // A report arriving after the comparison ran must remain in the history.
                $oldReports->where(fn (Builder $reports) => $reports
                    ->where('received_at', '<', $oldestKept->received_at)
                    ->orWhere(fn (Builder $tied) => $tied
                        ->where('received_at', $oldestKept->received_at)
                        ->where('id', '<', $oldestKept->id)));
            }

            $deleted += $this->pruneRows->execute($oldReports->toBase());
        });

        return $deleted;
    }
}
