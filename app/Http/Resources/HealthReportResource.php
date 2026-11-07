<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Services\ReportFindingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary of a stored report: when it arrived, its worst severity and the count per severity
 * of the findings that warn or fail, the checks per status, the worst finding, and (reports from
 * excubia-core 1.0) the core version and the PHP / Laravel / Node versions with their support
 * windows, the release and, for a deploy (#106), when it was deployed. The report body is sent
 * separately, on the project page.
 *
 * @mixin HealthReport
 */
class HealthReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HealthReport $report */
        $report = $this->resource;

        return [
            'id' => $report->id,
            'received_at' => $report->received_at->toIso8601String(),
            'generated_at' => $report->generated_at->toIso8601String(),
            'release' => $report->release,
            'deployed_at' => $report->deployed_at?->toIso8601String(),
            'worst_severity' => $report->worst_severity?->value,
            'counts' => [
                'critical' => $report->critical_count,
                'high' => $report->high_count,
                'medium' => $report->medium_count,
                'low' => $report->low_count,
                'info' => $report->info_count,
            ],
            'excubia_version' => is_string($report->report['excubiaVersion'] ?? null) ? $report->report['excubiaVersion'] : null,
            'runtime' => $this->runtime($report),
            'checks' => $this->checkCounts($report),
            'top_finding' => $this->topFinding($report),
        ];
    }

    /**
     * The report's `project` block (excubia-core 1.0): per runtime its version, cycle and support
     * end dates, or null. Older reports have none.
     *
     * @return array<string, array<string, string|null>|null>|null
     */
    private function runtime(HealthReport $report): ?array
    {
        $project = $report->report['project'] ?? null;

        if (! is_array($project)) {
            return null;
        }

        $runtime = [];

        foreach (['php', 'laravel', 'node'] as $name) {
            $entry = $project[$name] ?? null;
            $runtime[$name] = is_array($entry) ? [
                // Composer reports some versions with a leading "v" (v13.39.0); show them like PHP's.
                'version' => is_string($entry['version'] ?? null) ? ltrim($entry['version'], 'vV') : null,
                'cycle' => $entry['cycle'] ?? null,
                'security_support_ends_on' => $entry['securitySupportEndsOn'] ?? null,
                'active_support_ends_on' => $entry['activeSupportEndsOn'] ?? null,
            ] : null;
        }

        return $runtime;
    }

    /**
     * @return array{pass: int, warn: int, fail: int, skipped: int}
     */
    private function checkCounts(HealthReport $report): array
    {
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skipped' => 0];

        foreach ((array) ($report->report['checks'] ?? []) as $check) {
            $status = $check['status'] ?? null;

            if (is_string($status) && array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * The worst finding that warns or fails (first one at the worst severity), or null.
     *
     * @return array{severity: string, subject: string, message: string, check_name: string|null}|null
     */
    private function topFinding(HealthReport $report): ?array
    {
        $top = null;
        $topRank = PHP_INT_MAX;

        foreach (app(ReportFindingsService::class)->issues($report) as $finding) {
            $rank = SeverityEnum::tryFrom((string) ($finding['severity'] ?? ''))?->rank() ?? PHP_INT_MAX;

            if ($rank < $topRank) {
                $top = $finding;
                $topRank = $rank;
            }
        }

        return $top === null ? null : [
            'severity' => (string) $top['severity'],
            'subject' => (string) ($top['subject'] ?? ''),
            'message' => (string) ($top['message'] ?? ''),
            'check_name' => isset($top['checkName']) ? (string) $top['checkName'] : null,
        ];
    }
}
