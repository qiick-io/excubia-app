<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Sets the status of an exception group; `resolved_at` follows a resolve and clears otherwise.
 * A change made by a user is logged as a `status_changed` activity on the group (#276), so a
 * bulk resolve leaves one entry per group; setting the current status again records nothing.
 */
class UpdateExceptionStatusAction
{
    public function execute(ExceptionGroup $group, ExceptionStatusEnum $status, ?User $actor = null): ExceptionGroup
    {
        // Ingest can reopen a group concurrently. Lock the current state so each transition
        // changes the open count exactly once and rolls back with the group on failure.
        return DB::transaction(fn (): ExceptionGroup => $this->updateLocked(
            ExceptionGroup::query()->lockForUpdate()->findOrFail($group->id), $status, $actor,
        ), attempts: 3);
    }

    private function updateLocked(ExceptionGroup $group, ExceptionStatusEnum $status, ?User $actor): ExceptionGroup
    {
        if ($group->status === $status) {
            return $group;
        }

        $occurredAt = Date::now();
        $openChange = (int) ($status === ExceptionStatusEnum::Open) - (int) ($group->status === ExceptionStatusEnum::Open);
        $group->forceFill([
            'status' => $status,
            'resolved_at' => $status === ExceptionStatusEnum::Resolved ? $occurredAt : null,
        ])->save();

        if ($openChange !== 0) {
            $group->openChanges()->create(['project_id' => $group->project_id, 'occurred_at' => $occurredAt, 'delta' => $openChange]);
        }

        if ($actor !== null) {
            activity()
                ->performedOn($group)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(['status' => $status->value])
                ->log('Status changed');
        }

        return $group;
    }
}
