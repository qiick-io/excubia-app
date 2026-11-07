<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\FindingChangesData;
use App\Enums\AlertEventEnum;
use App\Enums\CheckCategoryEnum;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;

/**
 * The alerts a new report raises (#22), from what it changed in the finding lifecycle (#18):
 * findings that opened or came back (`finding.new`), findings resolved because their check ran
 * without them (`finding.resolved`), a worse or better worst severity of the open findings, and
 * a stale project reporting again. A push with fewer checks (standalone CLI, `--only`) resolves
 * nothing and so neither flaps `finding.resolved` / `finding.new` nor `project.recovered` /
 * `project.worsened`. Dismissed findings raise no finding alerts and leave the worst severity.
 * The first report of a project raises no finding alerts (everything would be "new"), except
 * `infrastructure.failed` (#109): a database, storage or disk failure that is new or back, or on
 * the first report, alerts at once at critical, instead of as `finding.new`, unless a
 * `health.failed` of the same check and fingerprint just went out (#143).
 */
class ReportAlertsService
{
    /** Finding alerts per report and kind, so a big upgrade does not flood the channels. */
    public const FINDINGS_LIMIT = 20;

    public function __construct(
        private AlertDispatcherService $alerts,
        private ReportFindingsService $findings,
    ) {}

    public function afterReport(Project $project, HealthReport $report, ?HealthReport $previous, bool $wasStale, FindingChangesData $changes): void
    {
        if ($wasStale) {
            $this->alerts->dispatch(AlertEventEnum::ProjectReportingAgain, $project, 'reporting again');
        }

        $new = $changes->new;
        $isImmediate = fn (array $finding): bool => ($finding['status'] ?? null) === 'fail'
            && in_array($finding['checkId'] ?? null, CheckCategoryEnum::IMMEDIATE_ALERT_CHECK_IDS, true);

        foreach (array_filter($new, $isImmediate) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::InfrastructureFailed, $project, $this->summary($finding), $this->details($finding), subject: $this->findings->identity($finding), fingerprints: is_string($finding['fingerprint'] ?? null) ? [$finding['fingerprint']] : []);
        }

        if ($previous === null) {
            return;
        }

        foreach (array_slice(array_values(array_filter($new, fn (array $finding): bool => ! $isImmediate($finding))), 0, self::FINDINGS_LIMIT) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::FindingNew, $project, $this->summary($finding), $this->details($finding), SeverityEnum::tryFrom((string) ($finding['severity'] ?? '')), $this->findings->identity($finding));
        }

        foreach (array_slice($changes->resolved, 0, self::FINDINGS_LIMIT) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::FindingResolved, $project, $this->summary($finding), $this->details($finding), subject: $this->findings->identity($finding));
        }

        $before = $changes->worstBefore?->rank() ?? count(SeverityEnum::cases());
        $after = $changes->worstAfter?->rank() ?? count(SeverityEnum::cases());

        if ($after < $before && $changes->worstAfter !== null) {
            $this->alerts->dispatch(AlertEventEnum::ProjectWorsened, $project, "worst severity is now {$changes->worstAfter->value}", ['worstSeverity' => $changes->worstAfter->value], $changes->worstAfter);
        } elseif ($after > $before) {
            $this->alerts->dispatch(AlertEventEnum::ProjectRecovered, $project, 'worst severity is now '.($changes->worstAfter->value ?? 'none'), ['worstSeverity' => $changes->worstAfter?->value]);
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
