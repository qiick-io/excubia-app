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
 */
class StorePushedReportAction
{
    public function __construct(
        private ReportAlertsService $reportAlerts,
        private StoreScheduleInventoryAction $scheduleInventory,
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
                    'report' => $report,
                    ...$this->summary($report),
                ]);

                // A push without `schedule` (older cores, the standalone CLI) keeps the last inventory (#110).
                if (is_array($report['schedule'] ?? null)) {
                    $this->scheduleInventory->execute($project, $report['schedule']);
                }

                $project->forceFill(['last_report_at' => $receivedAt, 'stale_alerted_at' => null])->save();
                $this->reportAlerts->afterReport($project, $healthReport, $previous, $wasStale);

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
}
