<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigns the selected exception groups or findings to one user, or unassigns them (#276):
 * each one through `AssignProjectItemAction`, so each gets its own activity entry (and a
 * finding its history event), in one transaction.
 */
class BulkAssignProjectItemsAction
{
    public function __construct(private readonly AssignProjectItemAction $assign) {}

    /**
     * @param  Collection<int, ExceptionGroup>|Collection<int, Finding>  $items
     */
    public function execute(Collection $items, User $actor, ?User $assignee): void
    {
        DB::transaction(function () use ($items, $actor, $assignee): void {
            foreach ($items as $item) {
                $this->assign->execute($item, $actor, $assignee);
            }
        });
    }
}
