<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a scheduled task (#110), for its most recent due time that can be judged:
 * - on_time: it ran within `lateAfterMinutes` of the due time; late: no run yet within that
 *   grace, or a run after it; missed: still no run when the next due time came; failed: a run
 *   failed; skipped: only skipped runs (overlap, `onOneServer`, filters), informational;
 * - scheduler_down: the task is late or missed while `excubia:heartbeat` is too, so the whole
 *   scheduler is down rather than this task;
 * - not_expected: limited to other environments; paused: the app is in maintenance mode and the
 *   task does not run then;
 * - pending: no due time can be judged yet; unknown: no run data with a `taskId` reached the app
 *   yet, or the expression or timezone cannot be read.
 */
enum ScheduledTaskStatusEnum: string
{
    case Pending = 'pending';
    case OnTime = 'on_time';
    case Late = 'late';
    case Missed = 'missed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case SchedulerDown = 'scheduler_down';
    case NotExpected = 'not_expected';
    case Paused = 'paused';
    case Unknown = 'unknown';

    /**
     * Whether the task is fine again, so the next problem alerts once more.
     */
    public function isHealthy(): bool
    {
        return in_array($this, [self::OnTime, self::Skipped, self::NotExpected, self::Paused], true);
    }

    /**
     * Whether a run that should have happened is missing (the statuses `sampleRate` makes uncertain).
     */
    public function isMissingRun(): bool
    {
        return $this === self::Late || $this === self::Missed;
    }
}
