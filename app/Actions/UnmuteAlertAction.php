<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\AlertMute;

/**
 * Ends a mute before its time (#114).
 */
class UnmuteAlertAction
{
    public function execute(AlertMute $mute): void
    {
        $mute->delete();

        // Audited with the acting user (#431).
        activity()
            ->performedOn($mute)
            ->event(ActivityEventEnum::AlertUnmuted->value)
            ->withProperties(['project_id' => $mute->project_id, 'event' => $mute->event, 'subject' => $mute->subject])
            ->log('Alert unmuted');
    }
}
