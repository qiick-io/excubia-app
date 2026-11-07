<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a finding across reports (#18). A finding is open while reports contain it
 * and resolved once a later report ran its check without it; it reopens when it comes back.
 * A dismissal is separate (it keeps the state), so a dismissed finding still resolves.
 */
enum FindingStatusEnum: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
