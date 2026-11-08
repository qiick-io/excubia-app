<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Models\AlertLog;
use App\Models\HealthReport;
use App\Models\Project;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * The end-of-life alerts (#157), from the `project` block of the latest report (PHP, Laravel and
 * Node with the security support end date of their cycle, excubia-core#92):
 * - `eol.approaching`: security support ends within one of `excubia.eol_alert_days` (90, 30 and 7
 *   by default); only the tightest threshold reached alerts, `high` from HIGH_FROM_DAYS days;
 * - `eol.reached`: security support has ended.
 * The subject is `<runtime>:<cycle>:<threshold days|reached>`, so each threshold of a cycle alerts
 * once; a project that upgrades has a new cycle and the old one stops alerting. An alert counts
 * as sent once it is in the history, unless a maintenance window or maintenance mode held it
 * back: then the next evaluation tries again. Runs after every report and daily.
 *
 * ponytail: "already sent" is the alert history, pruned after the longer telemetry retention
 * (90 days by default); a much shorter retention can repeat a threshold. Keep a sent marker per
 * project if that matters.
 */
class EolAlertsService
{
    public const HIGH_FROM_DAYS = 7;

    private const LABELS = ['php' => 'PHP', 'laravel' => 'Laravel', 'node' => 'Node.js'];

    public function __construct(
        private AlertDispatcherService $alerts,
        private ReportHealthService $health,
    ) {}

    /**
     * Sends the alerts due for the report (the project's latest by default); returns how many
     * went to the dispatcher.
     */
    public function evaluate(Project $project, ?HealthReport $report = null): int
    {
        $report ??= $project->latestReport;

        if ($report === null) {
            return 0;
        }

        $thresholds = $this->thresholds();
        $today = Date::today();
        $sent = 0;

        foreach ($this->health->runtimes($report->report) as $name => $runtime) {
            if ($runtime['cycle'] === null || $runtime['securitySupportEndsOn'] === null) {
                continue;
            }

            try {
                $endsOn = Date::parse($runtime['securitySupportEndsOn'])->startOfDay();
            } catch (Throwable) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays($endsOn, false);
            $threshold = $daysLeft <= 0 ? null : collect($thresholds)->filter(fn (int $days): bool => $daysLeft <= $days)->min();

            if ($daysLeft > 0 && $threshold === null) {
                continue;
            }

            $isReached = $daysLeft <= 0;
            $event = $isReached ? AlertEventEnum::EolReached : AlertEventEnum::EolApproaching;
            $subject = "{$name}:{$runtime['cycle']}:".($isReached ? 'reached' : $threshold);

            if ($this->wasSent($project, $event, $subject)) {
                continue;
            }

            $label = self::LABELS[$name].' '.$runtime['cycle'];
            $summary = $isReached
                ? "{$label}: security support ended on {$endsOn->toDateString()}"
                : "{$label}: security support ends on {$endsOn->toDateString()}, in {$daysLeft} days";

            $this->alerts->dispatch($event, $project, $summary, [
                'runtime' => $name,
                'version' => $runtime['version'],
                'cycle' => $runtime['cycle'],
                'securitySupportEndsOn' => $endsOn->toDateString(),
                'daysLeft' => $daysLeft,
                'thresholdDays' => $threshold,
            ], ! $isReached && $threshold <= self::HIGH_FROM_DAYS ? SeverityEnum::High : null, $subject);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return list<int> the configured lead times in days, positive only
     */
    private function thresholds(): array
    {
        return array_values(array_filter(array_map('intval', (array) config('excubia.eol_alert_days')), fn (int $days): bool => $days > 0));
    }

    /**
     * Whether the alert is already in the history: sent, skipped (no channel takes it) or muted.
     */
    private function wasSent(Project $project, AlertEventEnum $event, string $subject): bool
    {
        return AlertLog::query()
            ->where('project_id', $project->id)
            ->where('event', $event->value)
            ->where('subject', $subject)
            ->where(fn ($query) => $query->where('status', '!=', AlertLog::SUPPRESSED)->orWhereNotIn('reason', [AlertSuppressionService::MAINTENANCE_WINDOW, AlertSuppressionService::MAINTENANCE_MODE]))
            ->exists();
    }
}
