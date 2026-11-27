<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HealthReport;
use App\Models\Project;

/**
 * Provides a bounded history chart and a paginated report table. The latest deployed release
 * and total deploy count on Overview are independent of both history limits.
 */
class ProjectReportHistoryService
{
    private const CHART_REPORT_LIMIT = 100;

    /**
     * The history table uses the report body only for the core version on installations that
     * stored it. Check and finding details belong to the latest report, not the history rows.
     *
     * @return array<string, mixed>
     */
    public function paginate(Project $project, int $perPage): array
    {
        return $project->healthReports()
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (HealthReport $report): array => [
                'id' => $report->id,
                'received_at' => $report->received_at->toIso8601String(),
                'release' => $report->release,
                'deployed_at' => $report->deployed_at?->toIso8601String(),
                'worst_severity' => $report->worst_severity?->value,
                'counts' => $report->severityCounts(),
                'excubia_version' => is_string($report->report['excubiaVersion'] ?? null) ? $report->report['excubiaVersion'] : null,
            ])
            ->toArray();
    }

    /** @return list<array{received_at: string, counts: array{critical: int, high: int, medium: int, low: int, info: int}}> */
    public function chart(Project $project): array
    {
        return array_values($project->healthReports()
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(self::CHART_REPORT_LIMIT)
            ->get(['received_at', 'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count'])
            ->map(fn (HealthReport $report): array => [
                'received_at' => $report->received_at->toIso8601String(),
                'counts' => $report->severityCounts(),
            ])->all());
    }

    /** @return array{count: int, latest: array{release: string|null, deployed_at: string}|null} */
    public function deploySummary(Project $project): array
    {
        $deploys = $project->healthReports()
            ->deploys()
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(1, ['release', 'deployed_at'], page: 1);
        $latest = $deploys->first();

        return [
            'count' => $deploys->total(),
            'latest' => $latest?->deployed_at === null ? null : [
                'release' => $latest->release,
                'deployed_at' => $latest->deployed_at->toIso8601String(),
            ],
        ];
    }
}
