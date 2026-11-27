<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckCategoryEnum;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;

/**
 * Reads the findings of a stored excubia-core report. A finding "is an issue" when it warns or
 * fails; its identity across reports is `checkId` + `subject` + `advisoryId`, hashed by core into
 * the `fingerprint` the finding lifecycle (#18) keys on. `compare()` is a plain report-to-report
 * diff (the assistant's report comparison); alerts and the digest use the lifecycle instead.
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
        return $this->prioritize($this->issues($report));
    }

    /**
     * Findings in page order (see prioritized()), each with its check group; also the rows of the
     * finding lifecycle (#18).
     *
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    public function prioritize(array $findings): array
    {
        $issues = array_map(fn (array $finding): array => [
            ...$finding,
            'category' => CheckCategoryEnum::forCheck((string) ($finding['checkId'] ?? ''))->value,
        ], $findings);

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
     * Core's stable `fingerprint` of a finding: `sha256(checkId NUL subject NUL advisoryId)`
     * (excubia-core `docs/CONTRACTS.md`). Reports from cores before 1.0 have none; the same recipe
     * stands in, so their findings match the ones of newer reports.
     *
     * @param  array<string, mixed>  $finding
     */
    public function fingerprint(array $finding): string
    {
        $fingerprint = $finding['fingerprint'] ?? null;

        if (is_string($fingerprint) && preg_match('/^[0-9a-f]{64}$/', $fingerprint) === 1) {
            return $fingerprint;
        }

        return hash('sha256', implode("\0", [(string) ($finding['checkId'] ?? ''), (string) ($finding['subject'] ?? ''), (string) ($finding['advisoryId'] ?? '')]));
    }

    /**
     * The check ids that really ran in a report, so a finding missing from it is fixed rather than
     * unchecked (#18). A check is left out when the report does not have it (`--only`, `--skip`,
     * the standalone CLI with fewer checks), when it was skipped, or when one of its rows is a
     * data-source failure (an advisory API that did not answer proves nothing).
     *
     * @return list<string>
     */
    public function ranCheckIds(HealthReport $report): array
    {
        $failed = array_keys($this->dataSourceFailures($report));
        $checkIds = [];

        foreach ((array) ($report->report['checks'] ?? []) as $check) {
            if (is_string($check['checkId'] ?? null) && ($check['status'] ?? null) !== 'skipped' && ! in_array($check['checkId'], $failed, true)) {
                $checkIds[] = $check['checkId'];
            }
        }

        return $checkIds;
    }

    /**
     * The checks of a report with a row skipped because a data source failed
     * (`skipReason: data-source-failure`), so the report is incomplete.
     *
     * @return array<string, string> check id → check name
     */
    public function dataSourceFailures(HealthReport $report): array
    {
        $checks = [];

        foreach ((array) ($report->report['checks'] ?? []) as $check) {
            $hasDataSourceFailure = collect((array) ($check['findings'] ?? []))->contains(fn (mixed $finding): bool => is_array($finding)
                && ($finding['status'] ?? null) === 'skipped'
                && ($finding['skipReason'] ?? null) === 'data-source-failure');

            if ($hasDataSourceFailure && is_string($check['checkId'] ?? null)) {
                $checks[$check['checkId']] = is_string($check['name'] ?? null) ? $check['name'] : $check['checkId'];
            }
        }

        return $checks;
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
