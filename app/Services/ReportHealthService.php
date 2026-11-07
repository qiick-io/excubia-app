<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckCategoryEnum;
use App\Enums\SeverityEnum;

/**
 * The health summary of an excubia-core report for the project overview (#109) and its Licenses
 * tab (#108), read from the structured `details` of the findings (`docs/report-schema.json`):
 * the checks per group, the infrastructure numbers (disk, database size and largest tables,
 * connections, queue backlog, Redis memory, backups), the outdated packages per ecosystem by
 * patch / minor / major, and the license inventory with the denied, copyleft and unknown
 * packages. Names, counts and sizes only, as core sends them.
 *
 * @phpstan-type FlaggedLicense array{package: string, lockedVersion: string, licenses: list<string>, ecosystem: string, kind: 'denied'|'copyleft'|'unknown', severity: string}
 */
class ReportHealthService
{
    private const STATUS_RANK = ['fail' => 0, 'warn' => 1, 'pass' => 2, 'skipped' => 3];

    /** The check whose findings block the next Laravel major (#19). */
    public const UPGRADE_BLOCKER_CHECK = 'laravel-next-blocker';

    /**
     * The package checks of the advisory exposure (#20): check id => [kind, ecosystem].
     *
     * @var array<string, array{0: 'advisory'|'abandoned'|'stale', 1: 'composer'|'npm'}>
     */
    public const PACKAGE_RISK_CHECKS = [
        'composer-advisories' => ['advisory', 'composer'],
        'npm-advisories' => ['advisory', 'npm'],
        'composer-abandoned' => ['abandoned', 'composer'],
        'composer-stale' => ['stale', 'composer'],
    ];

    /**
     * @param  array<string, mixed>  $report
     * @return array{groups: list<array<string, mixed>>, infrastructure: array<string, mixed>, outdated: array<string, array{patch: int, minor: int, major: int}|null>, licenses: array<string, mixed>|null}
     */
    public function summary(array $report): array
    {
        return [
            'groups' => $this->groups($report),
            'infrastructure' => $this->infrastructure($report),
            'outdated' => [
                'composer' => $this->outdated($report, 'composer-outdated'),
                'npm' => $this->outdated($report, 'npm-outdated'),
            ],
            'licenses' => $this->licenses($report),
        ];
    }

    /**
     * Worst status of the infrastructure checks that ran (fail, warn, pass), or null when none
     * ran: the fleet column of the dashboard (#109).
     *
     * @param  array<string, mixed>  $report
     */
    public function infrastructureStatus(array $report): ?string
    {
        $statuses = [];

        foreach ($this->checks($report) as $check) {
            if (CheckCategoryEnum::forCheck($check['checkId']) === CheckCategoryEnum::Infrastructure) {
                $statuses[] = $check['status'];
            }
        }

        $worst = $this->worst($statuses);

        return $worst === 'skipped' ? null : $worst;
    }

    /**
     * The `dependency-licenses` findings that warn or fail: a denied license fails, a strong
     * copyleft one warns at medium, a missing or unknown one at low (core #134).
     *
     * ponytail: the kind is read from status and severity because the details carry none; if
     * core adds a `kind` detail, read it instead.
     *
     * @param  array<string, mixed>  $report
     * @return list<FlaggedLicense>
     */
    public function flaggedLicenses(array $report): array
    {
        $rows = [];

        foreach ($this->findings($report, 'dependency-licenses') as $finding) {
            $details = $this->details($finding);
            $severity = SeverityEnum::tryFrom((string) ($finding['severity'] ?? '')) ?? SeverityEnum::Low;

            $rows[] = [
                'package' => (string) ($details['package'] ?? $finding['subject'] ?? ''),
                'lockedVersion' => (string) ($details['lockedVersion'] ?? ''),
                'licenses' => array_values(array_filter((array) ($details['licenses'] ?? []), 'is_string')),
                'ecosystem' => (string) ($details['ecosystem'] ?? ''),
                'kind' => match (true) {
                    ($finding['status'] ?? null) === 'fail' => 'denied',
                    $severity->rank() <= SeverityEnum::Medium->rank() => 'copyleft',
                    default => 'unknown',
                },
                'severity' => $severity->value,
            ];
        }

        $kindRank = ['denied' => 0, 'copyleft' => 1, 'unknown' => 2];
        usort($rows, fn (array $first, array $second): int => [$kindRank[$first['kind']], $first['package']] <=> [$kindRank[$second['kind']], $second['package']]);

        return $rows;
    }

    /**
     * The report's `project` block (excubia-core 1.0), per runtime, versions without a leading
     * "v". Its support dates are the ones php-eol, laravel-eol and node-eol fetched (core #123),
     * present also when those checks pass; a runtime the project does not declare is left out.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array{version: string|null, cycle: string|null, securitySupportEndsOn: string|null, activeSupportEndsOn: string|null}>
     */
    public function runtimes(array $report): array
    {
        $project = $report['project'] ?? null;
        $runtimes = [];

        if (! is_array($project)) {
            return $runtimes;
        }

        foreach (['php', 'laravel', 'node'] as $name) {
            $entry = $project[$name] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $runtimes[$name] = [
                'version' => is_string($entry['version'] ?? null) ? ltrim($entry['version'], 'vV') : null,
                'cycle' => is_string($entry['cycle'] ?? null) ? $entry['cycle'] : null,
                'securitySupportEndsOn' => is_string($entry['securitySupportEndsOn'] ?? null) ? $entry['securitySupportEndsOn'] : null,
                'activeSupportEndsOn' => is_string($entry['activeSupportEndsOn'] ?? null) ? $entry['activeSupportEndsOn'] : null,
            ];
        }

        return $runtimes;
    }

    /**
     * Whether the project can move to the next Laravel major today (#19), from its
     * `laravel-next-blocker` findings: ready when no direct dependency blocks the target major,
     * blocked otherwise (a package whose only release allowing it is a pre-release warns and
     * still blocks), unknown when the check did not run or was skipped (Laravel not locked,
     * Packagist down). Private packages are never sent to Packagist: they are counted, not checked.
     * The Fleet page passes `$blockerFindings`, the open and not dismissed blockers of the finding
     * lifecycle (#148), in place of the ones of the report.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>|null  $blockerFindings
     * @return array{laravelVersion: string|null, targetMajor: int|null, status: 'ready'|'blocked'|'unknown', blockers: list<array{package: string, lockedVersion: string, isPreReleaseOnly: bool}>, uncheckedPackages: int}
     */
    public function upgradeReadiness(array $report, ?array $blockerFindings = null): array
    {
        $laravelVersion = $this->runtimes($report)['laravel']['version'] ?? null;
        $targetMajor = $laravelVersion !== null && preg_match('/^(\d+)/', $laravelVersion, $matches) === 1 ? (int) $matches[1] + 1 : null;
        $check = collect($this->checks($report))->firstWhere('checkId', self::UPGRADE_BLOCKER_CHECK);
        $isSkipped = $check === null;
        $blockers = [];
        $uncheckedPackages = 0;

        foreach ($check['findings'] ?? [] as $finding) {
            $details = $this->details($finding);

            if (($finding['status'] ?? null) === 'skipped') {
                // The private packages row carries a count; any other skipped row means the check did not run.
                $isSkipped = $isSkipped || ! is_int($details['count'] ?? null);
                $uncheckedPackages += (int) ($details['count'] ?? 0);
            }
        }

        foreach ($blockerFindings ?? $check['findings'] ?? [] as $finding) {
            if (! in_array($finding['status'] ?? null, ['warn', 'fail'], true)) {
                continue;
            }

            $details = $this->details($finding);
            $targetMajor = is_int($details['targetMajor'] ?? null) ? $details['targetMajor'] : $targetMajor;
            $blockers[] = $this->upgradeBlocker($finding);
        }

        usort($blockers, fn (array $first, array $second): int => [$first['isPreReleaseOnly'], $first['package']] <=> [$second['isPreReleaseOnly'], $second['package']]);

        return [
            'laravelVersion' => $laravelVersion,
            'targetMajor' => $targetMajor,
            'status' => match (true) {
                $isSkipped || $targetMajor === null => 'unknown',
                $blockers === [] => 'ready',
                default => 'blocked',
            },
            'blockers' => $blockers,
            'uncheckedPackages' => $uncheckedPackages,
        ];
    }

    /**
     * One `laravel-next-blocker` finding that warns or fails as a blocking package (#19).
     *
     * @param  array<string, mixed>  $finding
     * @return array{package: string, lockedVersion: string, isPreReleaseOnly: bool}
     */
    public function upgradeBlocker(array $finding): array
    {
        $details = $this->details($finding);

        return [
            'package' => (string) ($details['package'] ?? $finding['subject'] ?? ''),
            'lockedVersion' => (string) ($details['lockedVersion'] ?? ''),
            'isPreReleaseOnly' => ($finding['status'] ?? null) === 'warn',
        ];
    }

    /**
     * One finding of a package check (#20) that warns or fails: a known advisory (Composer or
     * npm), an abandoned or a stale Composer package, with its locked and fixed versions. Null for
     * a finding of another check.
     *
     * @param  array<string, mixed>  $finding
     * @return array{checkId: string, kind: 'advisory'|'abandoned'|'stale', ecosystem: 'composer'|'npm', package: string, advisoryId: string|null, lockedVersion: string, fixedVersions: list<string>, replacement: string|null, severity: string, evidenceUrl: string|null}|null
     */
    public function packageRisk(array $finding): ?array
    {
        $checkId = (string) ($finding['checkId'] ?? '');

        if (! isset(self::PACKAGE_RISK_CHECKS[$checkId])) {
            return null;
        }

        [$kind, $ecosystem] = self::PACKAGE_RISK_CHECKS[$checkId];
        $details = $this->details($finding);

        return [
            'checkId' => $checkId,
            'kind' => $kind,
            'ecosystem' => $ecosystem,
            'package' => (string) ($details['package'] ?? $finding['subject'] ?? ''),
            'advisoryId' => isset($finding['advisoryId']) ? (string) $finding['advisoryId'] : null,
            'lockedVersion' => ltrim((string) ($details['lockedVersion'] ?? ''), 'vV'),
            'fixedVersions' => array_values(array_filter((array) ($details['fixedVersions'] ?? []), 'is_string')),
            'replacement' => is_string($details['replacement'] ?? null) ? $details['replacement'] : null,
            'severity' => (SeverityEnum::tryFrom((string) ($finding['severity'] ?? '')) ?? SeverityEnum::Info)->value,
            'evidenceUrl' => is_string($finding['evidenceUrl'] ?? null) ? $finding['evidenceUrl'] : null,
        ];
    }

    /**
     * Every check of the report in its group, groups in page order, with the worst status of
     * the group and the number of issues (warn or fail findings) per check.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{category: string, status: string, checks: list<array{checkId: string, name: string, status: string, issues: int}>}>
     */
    private function groups(array $report): array
    {
        $groups = [];

        foreach ($this->checks($report) as $check) {
            $category = CheckCategoryEnum::forCheck($check['checkId']);
            $groups[$category->rank()] ??= ['category' => $category->value, 'status' => 'skipped', 'checks' => []];
            $groups[$category->rank()]['checks'][] = [
                'checkId' => $check['checkId'],
                'name' => $check['name'],
                'status' => $check['status'],
                'issues' => count(array_filter($check['findings'], fn (array $finding): bool => in_array($finding['status'] ?? null, ['warn', 'fail'], true))),
            ];
        }

        ksort($groups);

        return array_values(array_map(fn (array $group): array => [
            ...$group,
            'status' => $this->worst(array_column($group['checks'], 'status')),
        ], $groups));
    }

    /**
     * The numbers of the infrastructure checks that carry them. A value is null (or a list is
     * empty) when its check passed without a row, was skipped or did not run.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function infrastructure(array $report): array
    {
        $disk = $this->details($this->findings($report, 'laravel-disk-space')[0] ?? []);
        $size = $this->details($this->findings($report, 'laravel-database-size', false)[0] ?? []);
        $connections = $this->details($this->findings($report, 'laravel-database-connections')[0] ?? []);
        $backlog = array_map($this->details(...), $this->findings($report, 'laravel-queue-backlog'));
        $oldest = array_filter(array_column($backlog, 'oldestAgeMinutes'), 'is_int');

        return [
            'status' => $this->infrastructureStatus($report),
            'diskUsedPercent' => is_int($disk['usedPercent'] ?? null) ? $disk['usedPercent'] : null,
            'database' => isset($size['sizeMb']) ? [
                'connection' => (string) ($size['connection'] ?? ''),
                'sizeMb' => (float) $size['sizeMb'],
                'largestTables' => array_values(array_filter((array) ($size['largestTables'] ?? []), 'is_array')),
            ] : null,
            'databaseConnections' => is_int($connections['connectionCount'] ?? null) ? $connections['connectionCount'] : null,
            'queueBacklog' => $backlog === [] ? null : [
                'queues' => count($backlog),
                'pendingCount' => array_sum(array_map(fn (array $details): int => (int) ($details['pendingCount'] ?? 0), $backlog)),
                'oldestAgeMinutes' => $oldest === [] ? null : max($oldest),
            ],
            'redis' => array_map($this->details(...), $this->findings($report, 'laravel-redis-memory')),
            'backups' => array_map($this->details(...), $this->findings($report, 'laravel-backups')),
        ];
    }

    /**
     * Outdated direct dependencies of one ecosystem, each counted once at its furthest level:
     * a newer major, else a newer minor, else a newer patch. Null when the check did not run.
     *
     * @param  array<string, mixed>  $report
     * @return array{patch: int, minor: int, major: int}|null
     */
    private function outdated(array $report, string $checkId): ?array
    {
        $check = collect($this->checks($report))->firstWhere('checkId', $checkId);

        if ($check === null || $check['status'] === 'skipped') {
            return null;
        }

        $counts = ['patch' => 0, 'minor' => 0, 'major' => 0];

        foreach ($this->findings($report, $checkId) as $finding) {
            $details = $this->details($finding);

            if (! isset($details['lockedVersion'])) {
                continue;
            }

            $latestMinor = $details['latestMinor'] ?? null;
            $level = match (true) {
                ($details['isMajorBehind'] ?? false) === true => 'major',
                $latestMinor !== null && $latestMinor !== ($details['latestPatch'] ?? null) => 'minor',
                default => 'patch',
            };
            $counts[$level]++;
        }

        return $counts;
    }

    /**
     * The report's `licenses` inventory (core #134) with one row per license id (packages per
     * ecosystem, most used first) and the flagged packages, or null when the check did not run.
     *
     * @param  array<string, mixed>  $report
     * @return array{packageCount: int, byLicense: list<array{license: string, composer: int, npm: int, total: int}>, flagged: list<FlaggedLicense>}|null
     */
    private function licenses(array $report): ?array
    {
        $inventory = $report['licenses'] ?? null;

        if (! is_array($inventory)) {
            return null;
        }

        $rows = [];

        foreach (['composer', 'npm'] as $ecosystem) {
            foreach ((array) ($inventory['byEcosystem'][$ecosystem]['byLicense'] ?? []) as $entry) {
                $license = (string) ($entry['license'] ?? '');
                $rows[$license] ??= ['license' => $license, 'composer' => 0, 'npm' => 0, 'total' => 0];
                $rows[$license][$ecosystem] += (int) ($entry['packageCount'] ?? 0);
                $rows[$license]['total'] += (int) ($entry['packageCount'] ?? 0);
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $first, array $second): int => [$second['total'], $first['license']] <=> [$first['total'], $second['license']]);

        return [
            'packageCount' => (int) ($inventory['packageCount'] ?? 0),
            'byLicense' => $rows,
            'flagged' => $this->flaggedLicenses($report),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{checkId: string, name: string, status: string, findings: list<array<string, mixed>>}>
     */
    private function checks(array $report): array
    {
        $checks = [];

        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }

            $checks[] = [
                'checkId' => (string) ($check['checkId'] ?? ''),
                'name' => (string) ($check['name'] ?? ''),
                'status' => (string) ($check['status'] ?? 'skipped'),
                'findings' => array_values(array_filter((array) ($check['findings'] ?? []), 'is_array')),
            ];
        }

        return $checks;
    }

    /**
     * Findings of one check: the ones that warn or fail, or every non-skipped one.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function findings(array $report, string $checkId, bool $isIssuesOnly = true): array
    {
        $findings = [];

        foreach ($this->checks($report) as $check) {
            if ($check['checkId'] !== $checkId) {
                continue;
            }

            foreach ($check['findings'] as $finding) {
                $status = $finding['status'] ?? null;

                if ($isIssuesOnly ? in_array($status, ['warn', 'fail'], true) : $status !== 'skipped') {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function details(array $finding): array
    {
        return is_array($finding['details'] ?? null) ? $finding['details'] : [];
    }

    /**
     * @param  list<string>  $statuses
     */
    private function worst(array $statuses): string
    {
        $worst = 'skipped';

        foreach ($statuses as $status) {
            if ((self::STATUS_RANK[$status] ?? 3) < self::STATUS_RANK[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
