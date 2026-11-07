<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;

/**
 * The alerts a new report raises (#22), by comparing it with the project's previous report:
 * new and resolved findings, a worse or better worst severity, and a stale project reporting
 * again. The first report of a project raises no finding alerts (everything would be "new").
 */
class ReportAlertsService
{
    /** Finding alerts per report and kind, so a big upgrade does not flood the channels. */
    public const FINDINGS_LIMIT = 20;

    public function __construct(
        private AlertDispatcherService $alerts,
        private ReportFindingsService $findings,
    ) {}

    public function afterReport(Project $project, HealthReport $report, ?HealthReport $previous, bool $wasStale): void
    {
        if ($wasStale) {
            $this->alerts->dispatch(AlertEventEnum::ProjectReportingAgain, $project, 'reporting again');
        }

        if ($previous === null) {
            return;
        }

        $changes = $this->findings->compare($previous, $report);

        foreach (array_slice($changes['new'], 0, self::FINDINGS_LIMIT) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::FindingNew, $project, $this->summary($finding), $this->details($finding), SeverityEnum::tryFrom((string) ($finding['severity'] ?? '')), $this->findings->identity($finding));
        }

        foreach (array_slice($changes['resolved'], 0, self::FINDINGS_LIMIT) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::FindingResolved, $project, $this->summary($finding), $this->details($finding), subject: $this->findings->identity($finding));
        }

        $before = $previous->worst_severity?->rank() ?? count(SeverityEnum::cases());
        $after = $report->worst_severity?->rank() ?? count(SeverityEnum::cases());

        if ($after < $before && $report->worst_severity !== null) {
            $this->alerts->dispatch(AlertEventEnum::ProjectWorsened, $project, "worst severity is now {$report->worst_severity->value}", ['worstSeverity' => $report->worst_severity->value], $report->worst_severity);
        } elseif ($after > $before) {
            $this->alerts->dispatch(AlertEventEnum::ProjectRecovered, $project, 'worst severity is now '.($report->worst_severity->value ?? 'none'), ['worstSeverity' => $report->worst_severity?->value]);
        }
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function summary(array $finding): string
    {
        return trim(($finding['subject'] ?? '').': '.($finding['message'] ?? ''), ': ');
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function details(array $finding): array
    {
        return array_intersect_key($finding, array_flip(['checkId', 'checkName', 'severity', 'subject', 'message', 'advisoryId', 'fixHint']));
    }
}
