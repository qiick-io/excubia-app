<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One step of a finding's history (#18): seen for the first time, gone from a later report,
 * back after being resolved, dismissed with a reason, dismissal removed, or (un)assigned (#254).
 */
enum FindingEventEnum: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Reopened = 'reopened';
    case Dismissed = 'dismissed';
    case Undismissed = 'undismissed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
}
