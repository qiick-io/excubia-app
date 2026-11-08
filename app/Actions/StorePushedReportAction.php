<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use App\Services\ReportAlertsService;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Stores a report pushed by a project and updates `projects.last_report_at`. A repeated
 * `batchId` returns the stored report (`wasRecentlyCreated` false) and stores nothing new.
 * The report's optional `release` and `deployedAt` (#106) are kept in their own columns: a
 * report with `deployed_at` is the project's deploy marker. The optional `environment` (core
 * #203) becomes the project's environment; a report without one (standalone CLI, older cores)
 * keeps the last known value, and a value that does not match `Project::ENVIRONMENT_PATTERN` is
 * dropped, never stored. Each new report moves the project's finding lifecycle (#18) before the
 * alerts read what changed.
 */
class StorePushedReportAction
{
    public function __construct(
        private ReportAlertsService $reportAlerts,
        private StoreScheduleInventoryAction $scheduleInventory,
        private SyncFindingLifecycleAction $findingLifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $report  excubia-core JSON report, already validated
     */
    public function execute(Project $project, string $batchId, CarbonInterface $sentAt, array $report): HealthReport
    {
        $existing = $project->healthReports()->where('batch_id', $batchId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $report = $this->withSafeEvidenceUrls($report);

        if (isset($report['environment']) && ! (is_string($report['environment']) && preg_match(Project::ENVIRONMENT_PATTERN, $report['environment']) === 1)) {
            unset($report['environment']);
        }

        try {
            return DB::transaction(function () use ($project, $batchId, $sentAt, $report): HealthReport {
                $receivedAt = Date::now();

                // Reports from excubia-core before 1.0.0-beta.1 have no generatedAt: the
                // envelope's sentAt (built right after the scan) stands in for it.
                $generatedAt = is_string($report['generatedAt'] ?? null) ? Date::parse($report['generatedAt']) : $sentAt;

                $previous = $project->healthReports()->latest('received_at')->latest('id')->first();
                $wasStale = $project->stale_alerted_at !== null;

                $healthReport = $project->healthReports()->create([
                    'batch_id' => $batchId,
                    'received_at' => $receivedAt,
                    'generated_at' => $generatedAt,
                    'release' => is_string($report['release'] ?? null) ? $report['release'] : null,
                    'deployed_at' => is_string($report['deployedAt'] ?? null) ? Date::parse($report['deployedAt']) : null,
                    'report' => $report,
                    ...$this->summary($report),
                ]);

                // A push without `schedule` (older cores, the standalone CLI) keeps the last inventory (#110).
                if (is_array($report['schedule'] ?? null)) {
                    $this->scheduleInventory->execute($project, $report['schedule']);
                }

                $project->forceFill([
                    'last_report_at' => $receivedAt,
                    'stale_alerted_at' => null,
                    'environment' => $report['environment'] ?? $project->environment,
                ])->save();
                $changes = $this->findingLifecycle->execute($project, $healthReport);
                $this->reportAlerts->afterReport($project, $healthReport, $previous, $wasStale, $changes);

                return $healthReport;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent retry of the same batch won the race.
            return $project->healthReports()->where('batch_id', $batchId)->firstOrFail();
        }
    }

    /**
     * Counts the findings that warn or fail per severity and picks the worst one.
     *
     * @param  array<string, mixed>  $report
     * @return array{worst_severity: SeverityEnum|null, critical_count: int, high_count: int, medium_count: int, low_count: int, info_count: int}
     */
    private function summary(array $report): array
    {
        $counts = array_fill_keys(SeverityEnum::values(), 0);
        $worst = null;

        foreach ((array) ($report['checks'] ?? []) as $check) {
            foreach ((array) ($check['findings'] ?? []) as $finding) {
                if (! in_array($finding['status'] ?? null, ['warn', 'fail'], true)) {
                    continue;
                }

                $severity = SeverityEnum::from($finding['severity']);
                $counts[$severity->value]++;

                if ($worst === null || $severity->rank() < $worst->rank()) {
                    $worst = $severity;
                }
            }
        }

        return [
            'worst_severity' => $worst,
            'critical_count' => $counts['critical'],
            'high_count' => $counts['high'],
            'medium_count' => $counts['medium'],
            'low_count' => $counts['low'],
            'info_count' => $counts['info'],
        ];
    }

    /**
     * Drops an `evidenceUrl` that is not an http(s) URL: the pages render it as a link, so a
     * `javascript:` or `data:` value from a push would run in the viewer's session (#51).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function withSafeEvidenceUrls(array $report): array
    {
        foreach ((array) ($report['checks'] ?? []) as $checkIndex => $check) {
            foreach ((array) ($check['findings'] ?? []) as $findingIndex => $finding) {
                $url = $finding['evidenceUrl'] ?? null;

                if (is_string($url) && preg_match('#^https?://#i', $url) !== 1) {
                    $report['checks'][$checkIndex]['findings'][$findingIndex]['evidenceUrl'] = null;
                }
            }
        }

        return $report;
    }
}
