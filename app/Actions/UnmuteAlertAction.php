<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\AlertMute;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a mute before its time (#114).
 */
class UnmuteAlertAction
{
    /**
     * Removes the mute and audits it with the acting user (#431), together.
     */
    public function execute(AlertMute $mute, User $actor): void
    {
        DB::transaction(function () use ($mute, $actor): void {
            if (! $mute->delete()) {
                return;
            }

            activity()
                ->performedOn($mute->loadMissing('project')->project ?? $mute)
                ->causedBy($actor)
                ->event(ActivityEventEnum::AlertUnmuted->value)
                ->withProperties(['alert_mute_id' => $mute->id, 'event' => $mute->event])
                ->log(ActivityEventEnum::AlertUnmuted->value);
        });
    }
}
