<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
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

                // ponytail: the report has no generatedAt yet (qiick-io/excubia-core#92), so the
                // envelope's sentAt (built right after the scan) stands in for it.
                $healthReport = $project->healthReports()->create([
                    'batch_id' => $batchId,
                    'received_at' => $receivedAt,
                    'generated_at' => $sentAt,
                    'report' => $report,
                    ...$this->summary($report),
                ]);

                $project->forceFill(['last_report_at' => $receivedAt])->save();

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
