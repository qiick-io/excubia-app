<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\FindingChangesData;
use App\Enums\FindingEventEnum;
use App\Enums\FindingStatusEnum;
use App\Models\Finding;
use App\Models\HealthReport;
use App\Models\Project;
use App\Services\FindingLifecycleService;
use App\Services\ReportFindingsService;
use Illuminate\Support\Collection;

/**
 * Moves a project's findings through their lifecycle (#18) with a newly stored report, keyed by
 * core's `fingerprint`: a finding seen for the first time opens, one seen again stays open (or
 * reopens when a report had resolved it), and an open finding missing from the report resolves
 * only when its check really ran in it (ReportFindingsService::ranCheckIds): a standalone CLI
 * push with fewer checks, `--only` / `--skip` or a failed data source leaves it open. Writes one
 * `finding_events` row per transition. Call inside the report's transaction.
 *
 * ponytail: reports are applied in the order they arrive, not by `generatedAt`; a late push of an
 * older scan counts as the newest. Rows an excubia.json ignore rule removes look fixed (core
 * leaves them out of the report). Upgrade path: compare `generated_at` before resolving.
 */
class SyncFindingLifecycleAction
{
    public function __construct(
        private ReportFindingsService $findings,
        private FindingLifecycleService $lifecycle,
    ) {}

    public function execute(Project $project, HealthReport $report): FindingChangesData
    {
        $seenAt = $report->received_at;
        $issues = [];

        foreach ($this->findings->issues($report) as $issue) {
            $issues[$this->findings->fingerprint($issue)] = $issue;
        }

        /** @var Collection<string, Finding> $known */
        $known = $project->findings()
            ->where(fn ($query) => $query->where('status', FindingStatusEnum::Open->value)->orWhereIn('fingerprint', array_keys($issues)))
            ->get()
            ->keyBy('fingerprint');

        $worstBefore = $this->lifecycle->worstSeverity($known);
        $ranCheckIds = $this->findings->ranCheckIds($report);
        $new = $resolved = $unchanged = [];

        foreach ($issues as $fingerprint => $issue) {
            $finding = $known->get($fingerprint);

            if ($finding === null) {
                $finding = $project->findings()->create([
                    'fingerprint' => $fingerprint,
                    'check_id' => (string) $issue['checkId'],
                    'severity' => (string) $issue['severity'],
                    'status' => FindingStatusEnum::Open,
                    'finding' => $issue,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ]);
                $known->put($fingerprint, $finding);
                $this->record($finding, FindingEventEnum::Opened, $report);
                $new[] = $issue;

                continue;
            }

            if ($finding->status === FindingStatusEnum::Resolved) {
                $finding->forceFill(['status' => FindingStatusEnum::Open, 'resolved_at' => null, 'reopened_count' => $finding->reopened_count + 1]);
                $this->record($finding, FindingEventEnum::Reopened, $report);

                if (! $this->lifecycle->isDismissed($finding)) {
                    $new[] = $issue;
                }
            }

            // Loose comparison: MySQL hands JSON back with its keys reordered (#136).
            if ($finding->finding != $issue) {
                $finding->forceFill(['severity' => (string) $issue['severity'], 'finding' => $issue]);
            }

            $finding->forceFill(['last_seen_at' => $seenAt]);

            // Most findings of a report are the same as last time: one UPDATE moves them all.
            if (array_keys($finding->getDirty()) === ['last_seen_at']) {
                $unchanged[] = $finding->id;
                $finding->syncOriginal();
            } else {
                $finding->save();
            }
        }

        if ($unchanged !== []) {
            Finding::query()->whereKey($unchanged)->update(['last_seen_at' => $seenAt]);
        }

        foreach ($known as $fingerprint => $finding) {
            if (isset($issues[$fingerprint]) || $finding->status !== FindingStatusEnum::Open || ! in_array($finding->check_id, $ranCheckIds, true)) {
                continue;
            }

            $finding->forceFill(['status' => FindingStatusEnum::Resolved, 'resolved_at' => $seenAt])->save();
            $this->record($finding, FindingEventEnum::Resolved, $report);

            if (! $this->lifecycle->isDismissed($finding)) {
                $resolved[] = $finding->finding;
            }
        }

        return new FindingChangesData($new, $resolved, $worstBefore, $this->lifecycle->worstSeverity($known));
    }

    private function record(Finding $finding, FindingEventEnum $event, HealthReport $report): void
    {
        $finding->events()->create(['event' => $event, 'health_report_id' => $report->id, 'occurred_at' => $report->received_at]);
    }
}
