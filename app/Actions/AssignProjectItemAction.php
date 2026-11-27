<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FindingEventEnum;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Assigns an exception group or a finding to a user, or unassigns it (`$assignee` null) (#254).
 * The request has already checked that the assignee can see the project. Logged as an
 * `assigned` / `unassigned` activity on the item; a finding also gets the matching
 * `finding_events` row, so its history says who assigned it to whom. Assigning the current
 * assignee again changes and records nothing.
 */
class AssignProjectItemAction
{
    public function execute(ExceptionGroup|Finding $item, User $actor, ?User $assignee): ExceptionGroup|Finding
    {
        $assigneeId = $assignee?->id;

        if ($item->assigned_user_id === $assigneeId) {
            return $item;
        }

        return DB::transaction(function () use ($item, $actor, $assigneeId): ExceptionGroup|Finding {
            $item->forceFill(['assigned_user_id' => $assigneeId])->save();

            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->event($assigneeId === null ? 'unassigned' : 'assigned')
                ->withProperties(['assigned_user_id' => $assigneeId])
                ->log($assigneeId === null ? 'Unassigned' : 'Assigned');

            if ($item instanceof Finding) {
                $item->events()->create([
                    'event' => $assigneeId === null ? FindingEventEnum::Unassigned : FindingEventEnum::Assigned,
                    'user_id' => $actor->id,
                    'assigned_user_id' => $assigneeId,
                    'occurred_at' => Date::now(),
                ]);
            }

            return $item;
        });
    }
}
