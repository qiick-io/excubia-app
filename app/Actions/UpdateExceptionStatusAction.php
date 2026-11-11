<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ExceptionStatusEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Sets the status of an exception group; `resolved_at` follows a resolve and clears otherwise.
 * A change made by a user is logged as a `status_changed` activity on the group (#276), so a
 * bulk resolve leaves one entry per group; setting the current status again records nothing.
 */
class UpdateExceptionStatusAction
{
    public function execute(ExceptionGroup $group, ExceptionStatusEnum $status, ?User $actor = null): ExceptionGroup
    {
        if ($group->status === $status) {
            return $group;
        }

        $group->forceFill([
            'status' => $status,
            'resolved_at' => $status === ExceptionStatusEnum::Resolved ? Date::now() : null,
        ])->save();

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
