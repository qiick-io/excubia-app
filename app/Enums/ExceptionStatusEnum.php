<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State of an exception group (#26). A resolved group reopens when it occurs again; an ignored
 * one keeps counting but stays ignored.
 */
enum ExceptionStatusEnum: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
