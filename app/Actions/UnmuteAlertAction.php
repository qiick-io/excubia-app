<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AlertMute;

/**
 * Ends a mute before its time (#114).
 */
class UnmuteAlertAction
{
    public function execute(AlertMute $mute): void
    {
        $mute->delete();
    }
}
