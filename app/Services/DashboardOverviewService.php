<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The issue-first dashboard (#77) for a set of projects and a period: key numbers compared with
 * the previous period, the top issues grouped across projects, runtimes near or past the end of
 * support, and the configuration (posture) checks that warn or fail. Everything is read from the
 * stored excubia-core reports: package names, versions, counts and ids, never personal data.
 *
 * @phpstan-type Counts array{critical: int, high: int, medium: int, low: int, info: int}
 */
class DashboardOverviewService
{
    /** Configuration checks of excubia-core 1.0, shown apart from the dependency findings. */
    public const POSTURE_CHECK_IDS = [
        'laravel-debug-mode',
        'laravel-production-caches',
        'laravel-pending-migrations',
        'laravel-failed-jobs',
        'laravel-scheduler-heartbeat',
        'laravel-maintenance-mode',
    ];

    /** Most grouped issues returned, so the page stays light. */
    public const TOP_ISSUES_LIMIT = 25;

    /** A runtime "ends soon" this many days before its security support ends (as in the UI). */
    private const ENDING_SOON_DAYS = 180;

    /** End-of-support dates listed up to this many days ahead. */
    private const END_OF_SUPPORT_HORIZON_DAYS = 365;

    public function __construct(
        private ReportFindingsService $findings,
        private ProjectStalenessService $staleness,
    ) {}

    /**
     * @param  Collection<int, Project>  $projects  the scoped projects, with `latestReport` loaded
     * @return array<string, mixed>
     */
    public function overview(Collection $projects, int $periodDays): array
    {
        $today = CarbonImmutable::instance(Date::now());
        $previousAt = $today->subDays($periodDays);
        $current = $projects->map(fn (Project $project): ?HealthReport => $project->latestReport)->filter()->values();
        $previous = $this->latestReportsAt($projects, $previousAt);

        return [
            'kpis' => [
                'findings' => [
                    'counts' => $this->counts($current),
                    'total' => array_sum($this->counts($current)),
                    'previous' => $previous->isEmpty() ? null : array_sum($this->counts($previous)),
                ],
                'atRisk' => [
                    'value' => $this->atRisk($current),
                    'previous' => $previous->isEmpty() ? null : $this->atRisk($previous),
                ],
                'endOfSupport' => [
                    'value' => $this->endingRuntimes($current, $today),
                    'previous' => $previous->isEmpty() ? null : $this->endingRuntimes($previous, $previousAt),
                ],
                'stale' => [
                    'value' => $projects->filter(fn (Project $project): bool => $this->staleness->isStale($project))->count(),
                    'total' => $projects->count(),
                ],
            ],
            ...$this->topIssues($projects),
            'endOfSupport' => $this->endOfSupport($projects, $today),
            'configuration' => $this->configuration($projects),
        ];
    }

    /**
     * Each project's newest report received at or before `$at`.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, HealthReport>
     */
    private function latestReportsAt(Collection $projects, CarbonImmutable $at): Collection
    {
        return $projects
            ->map(fn (Project $project): ?HealthReport => $project->healthReports()
                ->where('received_at', '<=', $at)
                ->orderByDesc('received_at')
                ->first())
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, HealthReport>  $reports
     * @return Counts
     */
    private function counts(Collection $reports): array
    {
        $counts = array_fill_keys(SeverityEnum::values(), 0);

        foreach ($reports as $report) {
            foreach (SeverityEnum::values() as $severity) {
                $counts[$severity] += (int) $report->{$severity.'_count'};
            }
        }

        /** @var Counts $counts */
        return $counts;
    }

    /**
     * @param  Collection<int, HealthReport>  $reports
     */
    private function atRisk(Collection $reports): int
    {
        return $reports->filter(fn (HealthReport $report): bool => in_array($report->worst_severity, [SeverityEnum::Critical, SeverityEnum::High], true))->count();
    }

    /**
     * Runtimes out of security support, or ending within 180 days, as of `$at`.
     *
     * @param  Collection<int, HealthReport>  $reports
     */
    private function endingRuntimes(Collection $reports, CarbonImmutable $at): int
    {
        $limit = $at->addDays(self::ENDING_SOON_DAYS)->toDateString();
        $count = 0;

        foreach ($reports as $report) {
            foreach ($this->runtimes($report) as $runtime) {
                if ($runtime['securitySupportEndsOn'] !== null && $runtime['securitySupportEndsOn'] <= $limit) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * The findings that warn or fail in the latest reports, grouped by check, package (or
     * subject) and advisory, worst first and then by the number of projects affected.
     * Configuration checks are left out: they have their own block.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{topIssues: list<array<string, mixed>>, topIssuesTotal: int}
     */
    private function topIssues(Collection $projects): array
    {
        $groups = [];

        foreach ($projects as $project) {
            if ($project->latestReport === null) {
                continue;
            }

            foreach ($this->findings->issues($project->latestReport) as $finding) {
                $checkId = (string) ($finding['checkId'] ?? '');

                if (in_array($checkId, self::POSTURE_CHECK_IDS, true)) {
                    continue;
                }

                $details = is_array($finding['details'] ?? null) ? $finding['details'] : [];
                $package = is_string($details['package'] ?? null) ? $details['package'] : (string) ($finding['subject'] ?? '');
                $advisoryId = isset($finding['advisoryId']) ? (string) $finding['advisoryId'] : null;
                $key = implode('|', [$checkId, $package, $advisoryId ?? '']);
                $severity = SeverityEnum::tryFrom((string) ($finding['severity'] ?? '')) ?? SeverityEnum::Info;
                $locked = is_string($details['lockedVersion'] ?? null) ? ltrim($details['lockedVersion'], 'vV') : null;

                $group = $groups[$key] ?? [
                    'key' => $key,
                    'severity' => $severity,
                    'checkId' => $checkId,
                    'checkName' => $finding['checkName'] ?? null,
                    'package' => $package,
                    'message' => (string) ($finding['message'] ?? ''),
                    'fixHint' => (string) ($finding['fixHint'] ?? ''),
                    'advisoryId' => $advisoryId,
                    'evidenceUrl' => $finding['evidenceUrl'] ?? null,
                    'installed' => [],
                    'fixedVersions' => [],
                    'projects' => [],
                ];

                if ($severity->rank() < $group['severity']->rank()) {
                    $group['severity'] = $severity;
                }

                if ($locked !== null) {
                    $group['installed'][$locked] = $locked;
                }

                foreach ((array) ($details['fixedVersions'] ?? []) as $fixed) {
                    if (is_string($fixed)) {
                        $group['fixedVersions'][$fixed] = $fixed;
                    }
                }

                $group['projects'][$project->slug] = ['slug' => $project->slug, 'name' => $project->name, 'installed' => $locked];
                $groups[$key] = $group;
            }
        }

        usort($groups, fn (array $first, array $second): int => [$first['severity']->rank(), -count($first['projects'])] <=> [$second['severity']->rank(), -count($second['projects'])]);

        return [
            'topIssues' => array_map(fn (array $group): array => [
                ...$group,
                'severity' => $group['severity']->value,
                'installed' => array_values($group['installed']),
                'fixedVersions' => array_values($group['fixedVersions']),
                'projects' => array_values($group['projects']),
            ], array_slice($groups, 0, self::TOP_ISSUES_LIMIT)),
            'topIssuesTotal' => count($groups),
        ];
    }

    /**
     * Runtimes whose security support has ended or ends within a year, soonest first.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function endOfSupport(Collection $projects, CarbonImmutable $today): array
    {
        $horizon = $today->addDays(self::END_OF_SUPPORT_HORIZON_DAYS)->toDateString();
        $rows = [];

        foreach ($projects as $project) {
            if ($project->latestReport === null) {
                continue;
            }

            foreach ($this->runtimes($project->latestReport) as $name => $runtime) {
                if ($runtime['securitySupportEndsOn'] !== null && $runtime['securitySupportEndsOn'] <= $horizon) {
                    $rows[] = ['project' => ['slug' => $project->slug, 'name' => $project->name], 'runtime' => $name, ...$runtime];
                }
            }
        }

        usort($rows, fn (array $first, array $second): int => $first['securitySupportEndsOn'] <=> $second['securitySupportEndsOn']);

        return $rows;
    }

    /**
     * The configuration (posture) checks that warn or fail, per project, worst first.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array<string, mixed>>
     */
    private function configuration(Collection $projects): array
    {
        $rows = [];

        foreach ($projects as $project) {
            if ($project->latestReport === null) {
                continue;
            }

            foreach ($this->findings->issues($project->latestReport) as $finding) {
                if (! in_array($finding['checkId'] ?? null, self::POSTURE_CHECK_IDS, true)) {
                    continue;
                }

                $rows[] = [
                    'project' => ['slug' => $project->slug, 'name' => $project->name],
                    'checkId' => (string) $finding['checkId'],
                    'checkName' => $finding['checkName'] ?? null,
                    'severity' => (string) ($finding['severity'] ?? 'info'),
                    'subject' => (string) ($finding['subject'] ?? ''),
                    'message' => (string) ($finding['message'] ?? ''),
                    'fixHint' => (string) ($finding['fixHint'] ?? ''),
                ];
            }
        }

        usort($rows, fn (array $first, array $second): int => (SeverityEnum::tryFrom($first['severity'])?->rank() ?? 9) <=> (SeverityEnum::tryFrom($second['severity'])?->rank() ?? 9));

        return $rows;
    }

    /**
     * The report's `project` block (excubia-core 1.0), per runtime, versions without a leading "v".
     *
     * @return array<string, array{version: string|null, cycle: string|null, securitySupportEndsOn: string|null, activeSupportEndsOn: string|null}>
     */
    private function runtimes(HealthReport $report): array
    {
        $project = $report->report['project'] ?? null;
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
}
