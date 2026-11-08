<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Events sent to the outbound webhook, the Slack / Telegram notifiers and the email subscribers (#22). A finding event
 * takes the finding's severity; the others have the default below.
 */
enum AlertEventEnum: string
{
    case FindingNew = 'finding.new';
    case FindingResolved = 'finding.resolved';
    // A database, storage or disk failure (#109), sent at once, even on a project's first report.
    case InfrastructureFailed = 'infrastructure.failed';
    // A check of the minute-level health samples (#115) fails, debounced, and stops failing;
    // a server that was sending them went silent (#143).
    case HealthFailed = 'health.failed';
    case HealthRecovered = 'health.recovered';
    case HealthStale = 'health.stale';
    case ProjectWorsened = 'project.worsened';
    case ProjectRecovered = 'project.recovered';
    case ProjectStale = 'project.stale';
    case ProjectReportingAgain = 'project.reporting_again';
    case ProjectTokenChanged = 'project.token_changed';
    // A report whose checks were skipped because a data source failed (incomplete report).
    case ReportChecksSkipped = 'report.checks_skipped';
    // Pushes rejected for a missing, unknown, revoked or disabled token, N times in an hour.
    case PushRejected = 'push.rejected';
    case ProjectCreated = 'project.created';
    case ProjectDeleted = 'project.deleted';
    case ProjectDisabled = 'project.disabled';
    case ScheduleMissed = 'schedule.missed';
    case ScheduleFailed = 'schedule.failed';
    case SchedulerDown = 'schedule.scheduler_down';
    // Optional runtime rules (#107, #114), off until their threshold is set.
    case CacheHitRatioLow = 'runtime.cache_hit_ratio_low';
    case DeliveryFailed = 'runtime.delivery_failed';
    case LogSpike = 'runtime.log_spike';
    // AI usage (#129): estimated monthly spend at 80 % and 100 % of the project's budget, a
    // spend spike against the 7-day average and an error rate above the threshold; off by default.
    case AiBudgetWarning = 'ai.budget_warning';
    case AiBudgetExceeded = 'ai.budget_exceeded';
    case AiSpendSpike = 'ai.spend_spike';
    case AiErrorRate = 'ai.error_rate';
    // Laravel Cashier webhooks (#178): failed webhooks of the last hour at a threshold, and
    // failed payments reported by the provider; off by default.
    case BillingWebhookFailures = 'billing.webhook_failures';
    case BillingPaymentFailed = 'billing.payment_failed';
    // PHP, Laravel or Node security support ends within a lead time, or has ended (#157).
    case EolApproaching = 'eol.approaching';
    case EolReached = 'eol.reached';
    case Ping = 'ping';

    /**
     * @return list<string> the events a notifier can subscribe to (not `ping`)
     */
    public static function selectableValues(): array
    {
        return array_column(array_filter(self::cases(), fn (self $event): bool => $event !== self::Ping), 'value');
    }

    /**
     * @return list<string> the events whose severity admins can change (#22): not those that
     *                      take it from the finding or the report (`finding.new`, `project.worsened`)
     */
    public static function severityEditableValues(): array
    {
        return array_values(array_diff(self::selectableValues(), [self::FindingNew->value, self::ProjectWorsened->value]));
    }

    /**
     * The built-in severity; Settings → Integrations can override it (SettingsRepositoryService::alertEventSeverity()).
     */
    public function defaultSeverity(): SeverityEnum
    {
        return match ($this) {
            self::InfrastructureFailed => SeverityEnum::Critical,
            self::ProjectStale, self::HealthStale, self::ScheduleMissed, self::CacheHitRatioLow, self::EolApproaching, self::AiBudgetWarning, self::AiSpendSpike, self::BillingPaymentFailed => SeverityEnum::Medium,
            self::FindingNew, self::HealthFailed, self::ProjectWorsened, self::ScheduleFailed, self::SchedulerDown, self::DeliveryFailed, self::LogSpike, self::PushRejected, self::EolReached, self::AiBudgetExceeded, self::AiErrorRate, self::BillingWebhookFailures => SeverityEnum::High,
            self::ReportChecksSkipped => SeverityEnum::Low,
            default => SeverityEnum::Info,
        };
    }
}
