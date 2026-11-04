<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HealthReport;

/**
 * Reads the findings of a stored excubia-core report. A finding "is an issue" when it warns or
 * fails; its identity across reports is `checkId` + `subject` + `advisoryId`. The comparison
 * here is also what alerts (#22) build on.
 */
class ReportFindingsService
{
    /**
     * Findings that warn or fail, each with the name of its check.
     *
     * @return list<array<string, mixed>>
     */
    public function issues(HealthReport $report): array
    {
        $issues = [];

        foreach ((array) ($report->report['checks'] ?? []) as $check) {
            foreach ((array) ($check['findings'] ?? []) as $finding) {
                if (in_array($finding['status'] ?? null, ['warn', 'fail'], true)) {
                    $issues[] = ['checkName' => $check['name'] ?? null, ...$finding];
                }
            }
        }

        return $issues;
    }

    /**
     * New and resolved issues of `$newer` compared with `$older`.
     *
     * @return array{new: list<array<string, mixed>>, resolved: list<array<string, mixed>>}
     */
    public function compare(HealthReport $older, HealthReport $newer): array
    {
        $before = $this->keyed($older);
        $after = $this->keyed($newer);

        return [
            'new' => array_values(array_diff_key($after, $before)),
            'resolved' => array_values(array_diff_key($before, $after)),
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    public function identity(array $finding): string
    {
        return implode('|', [$finding['checkId'] ?? '', $finding['subject'] ?? '', $finding['advisoryId'] ?? '']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function keyed(HealthReport $report): array
    {
        $keyed = [];

        foreach ($this->issues($report) as $finding) {
            $keyed[$this->identity($finding)] = $finding;
        }

        return $keyed;
    }
}
