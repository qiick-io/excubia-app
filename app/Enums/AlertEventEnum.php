<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Events sent to the outbound webhook and the Slack / Telegram notifiers (#22). A finding event
 * takes the finding's severity; the others have the default below.
 */
enum AlertEventEnum: string
{
    case FindingNew = 'finding.new';
    case FindingResolved = 'finding.resolved';
    case ProjectWorsened = 'project.worsened';
    case ProjectRecovered = 'project.recovered';
    case ProjectStale = 'project.stale';
    case ProjectReportingAgain = 'project.reporting_again';
    case ProjectTokenChanged = 'project.token_changed';
    case ScheduleMissed = 'schedule.missed';
    case ScheduleFailed = 'schedule.failed';
    case SchedulerDown = 'schedule.scheduler_down';
    case Ping = 'ping';

    /**
     * @return list<string> the events a notifier can subscribe to (not `ping`)
     */
    public static function selectableValues(): array
    {
        return array_column(array_filter(self::cases(), fn (self $event): bool => $event !== self::Ping), 'value');
    }

    public function defaultSeverity(): SeverityEnum
    {
        return match ($this) {
            self::ProjectStale, self::ScheduleMissed => SeverityEnum::Medium,
            self::FindingNew, self::ProjectWorsened, self::ScheduleFailed, self::SchedulerDown => SeverityEnum::High,
            default => SeverityEnum::Info,
        };
    }
}
