<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ScheduleContextData;
use App\Data\ScheduledTaskEvaluationData;
use App\Enums\ScheduledTaskStatusEnum;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Works out when a scheduled task (#110) was due and judges the most recent due time that can be
 * judged, from its runs (`ScheduledTaskRun`). Reads only; `EvaluateScheduledTasksAction` stores
 * the result and alerts.
 *
 * Due times come from `expression` read in `timezone`, as Laravel's scheduler reads them: a
 * minute is due when its wall-clock time there matches, so a time skipped by a DST change is not
 * due and a repeated one is due twice. A six-field expression (seconds first) runs more often
 * than once a minute; runs arrive per minute, so its seconds field is dropped.
 *
 * Data reaches the app with a delay (`excubia:push-runtime` runs every minute, the batch covers
 * the minutes before), so due times are judged up to `PUSH_DELAY_MINUTES` before now.
 */
class ScheduledTaskEvaluationService
{
    public const PUSH_DELAY_MINUTES = 2;

    /** Due times looked back per evaluation: enough for an every-minute task with an hour of grace. */
    private const MAX_DUE_TIMES = 62;

    /**
     * @param  iterable<ScheduledTaskRun>  $runs  the task's runs since `earliestDueTime()` at least
     */
    public function evaluate(ScheduledTask $task, ScheduleContextData $context, iterable $runs): ScheduledTaskEvaluationData
    {
        $cron = $this->cron($task->expression);
        $timezone = $this->timezone($task->timezone);

        if ($cron === null || $timezone === null) {
            return new ScheduledTaskEvaluationData(ScheduledTaskStatusEnum::Unknown);
        }

        $nextDueAt = $this->nextDueAfter($cron, $timezone, $context->now);
        $environments = $task->environments;

        if ($environments !== [] && $context->environment !== null && ! in_array($context->environment, $environments, true)) {
            return new ScheduledTaskEvaluationData(ScheduledTaskStatusEnum::NotExpected, nextDueAt: $nextDueAt);
        }

        if ($context->isInMaintenanceMode && ! $task->can_run_in_maintenance_mode) {
            return new ScheduledTaskEvaluationData(ScheduledTaskStatusEnum::Paused, nextDueAt: $nextDueAt);
        }

        if ($context->runsSince === null) {
            return new ScheduledTaskEvaluationData(ScheduledTaskStatusEnum::Unknown, nextDueAt: $nextDueAt);
        }

        $isUncertain = ($environments !== [] && $context->environment === null)
            || ($context->sampleRate !== null && $context->sampleRate < 1);
        $horizon = $this->horizon($context);
        $dueTimes = $this->dueTimes($task, $context);
        $runs = collect($runs);

        foreach ($dueTimes as $index => $dueAt) {
            $until = $index === 0 ? ($this->nextDueAfter($cron, $timezone, $dueAt) ?? $dueAt->addYear()) : $dueTimes[$index - 1];
            $window = $runs->filter(fn (ScheduledTaskRun $run): bool => $run->bucket->gte($dueAt) && $run->bucket->lt($until));
            $status = $this->judge($window, $dueAt, $until, $horizon, $task->late_after_minutes);

            if ($status !== null) {
                return new ScheduledTaskEvaluationData($status, $dueAt, $nextDueAt, $isUncertain && $window->isEmpty());
            }
        }

        return new ScheduledTaskEvaluationData(ScheduledTaskStatusEnum::Pending, nextDueAt: $nextDueAt);
    }

    /**
     * The oldest due time `evaluate()` may judge, so the caller loads only the runs it needs.
     * Null when there is none.
     */
    public function earliestDueTime(ScheduledTask $task, ScheduleContextData $context): ?CarbonImmutable
    {
        $dueTimes = $this->dueTimes($task, $context);

        return $dueTimes === [] ? null : end($dueTimes);
    }

    /**
     * The due times up to the horizon, newest first: as many as it takes to reach one whose grace
     * is over (`lateAfterMinutes` + 2 for an every-minute task, at most MAX_DUE_TIMES), never before the task appeared
     * or the app had run data.
     *
     * @return list<CarbonImmutable> UTC
     */
    private function dueTimes(ScheduledTask $task, ScheduleContextData $context): array
    {
        $cron = $this->cron($task->expression);
        $timezone = $this->timezone($task->timezone);

        if ($cron === null || $timezone === null || $context->runsSince === null) {
            return [];
        }

        $since = $context->runsSince;

        if ($task->created_at !== null && $task->created_at->gt($since)) {
            $since = CarbonImmutable::instance($task->created_at);
        }

        $dueTimes = [];
        $cursor = $this->horizon($context)->setTimezone($timezone);
        $isCurrentAllowed = true;
        // ponytail: at most MAX_DUE_TIMES back; a frequent task with a longer grace stays pending.
        $wanted = min($task->late_after_minutes + 2, self::MAX_DUE_TIMES);
        // A DST gap removes at most one due time a day, so a few spare attempts are enough.
        $attempts = $wanted + 3;

        while (count($dueTimes) < $wanted && $attempts-- > 0) {
            $candidate = $this->previousRun($cron, $timezone, $cursor, $isCurrentAllowed);

            if ($candidate === null || $candidate->lt($since)) {
                break;
            }

            $cursor = $candidate;
            $isCurrentAllowed = false;

            if ($this->isWallClockDue($cron, $candidate)) {
                $dueTimes[] = $candidate->utc();
            }
        }

        return $dueTimes;
    }

    /**
     * The status of one due time from the runs in its window (due time to next due time), or
     * null while it cannot be judged yet.
     *
     * @param  Collection<int, ScheduledTaskRun>  $window
     */
    private function judge(Collection $window, CarbonImmutable $dueAt, CarbonImmutable $until, CarbonImmutable $horizon, int $lateAfterMinutes): ?ScheduledTaskStatusEnum
    {
        $lateAt = $dueAt->addMinutes($lateAfterMinutes);

        if ($window->contains('outcome', 'failed')) {
            return ScheduledTaskStatusEnum::Failed;
        }

        $finished = $window->where('outcome', 'finished');

        if ($finished->isNotEmpty()) {
            return $finished->contains(fn (ScheduledTaskRun $run): bool => $run->bucket->lt($lateAt))
                ? ScheduledTaskStatusEnum::OnTime
                : ScheduledTaskStatusEnum::Late;
        }

        if ($window->isNotEmpty()) {
            return ScheduledTaskStatusEnum::Skipped;
        }

        if ($horizon->lt($lateAt)) {
            return null;
        }

        return $horizon->gte($until) ? ScheduledTaskStatusEnum::Missed : ScheduledTaskStatusEnum::Late;
    }

    private function horizon(ScheduleContextData $context): CarbonImmutable
    {
        return $context->now->utc()->startOfMinute()->subMinutes(self::PUSH_DELAY_MINUTES);
    }

    /**
     * The next wall-clock due time strictly after `$after`, UTC.
     */
    private function nextDueAfter(CronExpression $cron, DateTimeZone $timezone, CarbonImmutable $after): ?CarbonImmutable
    {
        $cursor = $after->setTimezone($timezone);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $candidate = CarbonImmutable::instance($cron->getNextRunDate($cursor, 0, false, $timezone->getName()));
            } catch (Throwable) {
                return null;
            }

            if ($this->isWallClockDue($cron, $candidate)) {
                return $candidate->utc();
            }

            $cursor = $candidate;
        }

        return null;
    }

    private function previousRun(CronExpression $cron, DateTimeZone $timezone, CarbonImmutable $before, bool $isCurrentAllowed): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::instance($cron->getPreviousRunDate($before, 0, $isCurrentAllowed, $timezone->getName()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Laravel matches the local wall-clock time (`Event::expressionPasses()`): the cron library
     * moves a time that a DST change skips to the next hour, which the scheduler never runs.
     */
    private function isWallClockDue(CronExpression $cron, CarbonImmutable $local): bool
    {
        return $cron->isDue($local->format('Y-m-d H:i:00'), 'UTC');
    }

    /**
     * The minute-level expression, or null when it cannot be read. Six fields: seconds first.
     */
    private function cron(string $expression): ?CronExpression
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) === 6) {
            $expression = implode(' ', array_slice($fields, 1));
        }

        return CronExpression::isValidExpression($expression) ? new CronExpression($expression) : null;
    }

    private function timezone(string $timezone): ?DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
