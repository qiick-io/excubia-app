<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckCategoryEnum;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;

/**
 * Reads the findings of a stored excubia-core report. A finding "is an issue" when it warns or
 * fails; its identity across reports is `checkId` + `subject` + `advisoryId`. The comparison
 * here is also what alerts (#22) build on.
 */
class ReportFindingsService
{
    /** The core check that warns while the app is down (`php artisan down`). */
    public const MAINTENANCE_CHECK = 'laravel-maintenance-mode';

    /**
     * Whether the project's latest report says the app is in maintenance mode (schedule
     * evaluation #110, alert suppression #114).
     */
    public function isInMaintenanceMode(Project $project): bool
    {
        $report = $project->healthReports()->latest('received_at')->latest('id')->first();

        return $report !== null && collect($this->issues($report))->contains('checkId', self::MAINTENANCE_CHECK);
    }

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
     * The issues in the order the project page lists them (#109), each with its check group:
     * failing infrastructure first (the app cannot run without it), then by severity, then by
     * group (infrastructure, configuration, dependencies, other), keeping the report order.
     *
     * @return list<array<string, mixed>>
     */
    public function prioritized(HealthReport $report): array
    {
        $issues = array_map(fn (array $finding): array => [
            ...$finding,
            'category' => CheckCategoryEnum::forCheck((string) ($finding['checkId'] ?? ''))->value,
        ], $this->issues($report));

        $sortKey = fn (array $finding): array => [
            $finding['category'] === CheckCategoryEnum::Infrastructure->value && ($finding['status'] ?? null) === 'fail' ? 0 : 1,
            SeverityEnum::tryFrom((string) ($finding['severity'] ?? ''))?->rank() ?? count(SeverityEnum::cases()),
            CheckCategoryEnum::from($finding['category'])->rank(),
        ];

        usort($issues, fn (array $first, array $second): int => $sortKey($first) <=> $sortKey($second));

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
