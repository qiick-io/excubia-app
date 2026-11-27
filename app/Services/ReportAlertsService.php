<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\FindingChangesData;
use App\Enums\AlertEventEnum;
use App\Enums\CheckCategoryEnum;
use App\Enums\SeverityEnum;
use App\Models\Finding;
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
 * `health.failed` of the same check and fingerprint just went out (#143). A check whose data
 * source failed in this report and not in the previous one raises `report.checks_skipped`.
 * The end-of-life alerts of the report's runtimes (#157) follow, also on the first report.
 * All of a report's alerts go out as one message per Slack, Telegram and email channel.
 * A finding alert of an assigned finding (#254) names the assignee in its summary and data, so
 * the channel tells who is on it.
 */
class ReportAlertsService
{
    /** Finding alerts per report and kind, so a big upgrade does not flood the channels. */
    public const FINDINGS_LIMIT = 20;

    public function __construct(
        private AlertDispatcherService $alerts,
        private ReportFindingsService $findings,
        private EolAlertsService $eol,
    ) {}

    public function afterReport(Project $project, HealthReport $report, ?HealthReport $previous, bool $wasStale, FindingChangesData $changes): void
    {
        $this->alerts->grouped(function () use ($project, $report, $previous, $wasStale, $changes): void {
            $this->raise($project, $report, $previous, $wasStale, $changes);
            $this->eol->evaluate($project, $report);
        });
    }

    private function raise(Project $project, HealthReport $report, ?HealthReport $previous, bool $wasStale, FindingChangesData $changes): void
    {
        $skipped = array_diff_key($this->findings->dataSourceFailures($report), $previous === null ? [] : $this->findings->dataSourceFailures($previous));

        if ($skipped !== []) {
            ksort($skipped);
            $this->alerts->dispatch(AlertEventEnum::ReportChecksSkipped, $project, 'skipped, a data source failed: '.implode(', ', $skipped), [
                'checkIds' => array_keys($skipped),
                'count' => count($skipped),
            ], subject: implode(',', array_keys($skipped)));
        }

        if ($wasStale) {
            $this->alerts->dispatch(AlertEventEnum::ProjectReportingAgain, $project, 'reporting again');
        }

        $new = $changes->new;
        $assignees = $this->assigneesByFingerprint($project, [...$new, ...$changes->resolved]);
        $isImmediate = fn (array $finding): bool => ($finding['status'] ?? null) === 'fail'
            && in_array($finding['checkId'] ?? null, CheckCategoryEnum::IMMEDIATE_ALERT_CHECK_IDS, true);

        foreach (array_filter($new, $isImmediate) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::InfrastructureFailed, $project, $this->summary($finding), $this->details($finding), subject: $this->findings->identity($finding), fingerprints: is_string($finding['fingerprint'] ?? null) ? [$finding['fingerprint']] : []);
        }

        if ($previous === null) {
            return;
        }

        foreach (array_slice(array_values(array_filter($new, fn (array $finding): bool => ! $isImmediate($finding))), 0, self::FINDINGS_LIMIT) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::FindingNew, $project, $this->summary($finding, $assignees), $this->details($finding, $assignees), SeverityEnum::tryFrom((string) ($finding['severity'] ?? '')), $this->findings->identity($finding));
        }

        foreach (array_slice($changes->resolved, 0, self::FINDINGS_LIMIT) as $finding) {
            $this->alerts->dispatch(AlertEventEnum::FindingResolved, $project, $this->summary($finding, $assignees), $this->details($finding, $assignees), subject: $this->findings->identity($finding));
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
     * The assignee names of the project's findings among `$findings`, keyed by fingerprint (#254):
     * one query for the whole report.
     *
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, string>
     */
    private function assigneesByFingerprint(Project $project, array $findings): array
    {
        $fingerprints = array_values(array_filter(array_column($findings, 'fingerprint'), is_string(...)));

        if ($fingerprints === []) {
            return [];
        }

        return $project->findings()
            ->whereIn('fingerprint', $fingerprints)
            ->whereNotNull('assigned_user_id')
            ->with('assignee:id,first_name,last_name')
            ->get()
            ->filter(fn (Finding $finding): bool => $finding->assignee !== null)
            ->mapWithKeys(fn (Finding $finding): array => [$finding->fingerprint => (string) $finding->assignee?->name])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, string>  $assignees
     */
    private function summary(array $finding, array $assignees = []): string
    {
        $summary = trim(($finding['subject'] ?? '').': '.($finding['message'] ?? ''), ': ');
        $assignee = $this->assigneeOf($finding, $assignees);

        return $assignee === null ? $summary : "{$summary} (assigned to {$assignee})";
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, string>  $assignees
     * @return array<string, mixed>
     */
    private function details(array $finding, array $assignees = []): array
    {
        $details = array_intersect_key($finding, array_flip(['checkId', 'checkName', 'severity', 'subject', 'message', 'advisoryId', 'fixHint']));
        $assignee = $this->assigneeOf($finding, $assignees);

        return $assignee === null ? $details : [...$details, 'assignee' => $assignee];
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, string>  $assignees
     */
    private function assigneeOf(array $finding, array $assignees): ?string
    {
        $fingerprint = $finding['fingerprint'] ?? null;

        return is_string($fingerprint) ? ($assignees[$fingerprint] ?? null) : null;
    }
}
