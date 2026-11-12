<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckCategoryEnum;
use App\Enums\ExceptionStatusEnum;
use App\Enums\SeverityEnum;
use App\Models\ExceptionGroup;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
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
    /** Most grouped issues returned, so the page stays light. */
    public const TOP_ISSUES_LIMIT = 25;

    /** A runtime "ends soon" this many days before its security support ends (as in the UI). */
    private const ENDING_SOON_DAYS = 180;

    /** End-of-support dates listed up to this many days ahead. */
    private const END_OF_SUPPORT_HORIZON_DAYS = 365;

    public function __construct(
        private ReportFindingsService $findings,
        private ProjectStalenessService $staleness,
        private FixSuggestionService $fixes,
        private ReportHealthService $health,
    ) {}

    /**
     * Configuration and infrastructure checks (#109) have their own block, apart from the
     * dependency findings.
     */
    private function isPosture(string $checkId): bool
    {
        return in_array(CheckCategoryEnum::forCheck($checkId), [CheckCategoryEnum::Configuration, CheckCategoryEnum::Infrastructure], true);
    }

    /**
     * Fleet license view (#108): each denied, copyleft or unknown license of the latest reports
     * with the projects and packages that use it, denied first, then by the number of projects.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{license: string, kind: string, packages: list<string>, projects: list<array{slug: string, name: string}>}>
     */
    private function licenses(Collection $projects): array
    {
        $rows = [];

        foreach ($projects as $project) {
            if ($project->latestReport === null) {
                continue;
            }

            foreach ($this->health->flaggedLicenses($project->latestReport->report) as $flagged) {
                foreach ($flagged['licenses'] === [] ? ['NOASSERTION'] : $flagged['licenses'] as $license) {
                    $key = $flagged['kind'].'|'.$license;
                    $rows[$key] ??= ['license' => $license, 'kind' => $flagged['kind'], 'packages' => [], 'projects' => []];
                    $rows[$key]['packages'][$flagged['package']] = $flagged['package'];
                    $rows[$key]['projects'][$project->slug] = ['slug' => $project->slug, 'name' => $project->name];
                }
            }
        }

        $kindRank = ['denied' => 0, 'copyleft' => 1, 'unknown' => 2];
        $rows = array_values(array_map(fn (array $row): array => [
            ...$row,
            'packages' => array_values($row['packages']),
            'projects' => array_values($row['projects']),
        ], $rows));
        usort($rows, fn (array $first, array $second): int => [$kindRank[$first['kind']], -count($first['projects']), $first['license']] <=> [$kindRank[$second['kind']], -count($second['projects']), $second['license']]);

        return $rows;
    }

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
            'licenses' => $this->licenses($projects),
            ...$this->telemetry($projects, $today, $periodDays),
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
            foreach ($this->health->runtimes($report->report) as $runtime) {
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
     * Configuration and infrastructure checks are left out: they have their own block.
     * Packages that repeat one check's message ("Latest release does not allow Laravel 14.")
     * then share one row with the list of packages (#291), so different problems stay visible.
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

                if ($this->isPosture($checkId)) {
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
                    'targetMajor' => is_int($details['targetMajor'] ?? null) ? $details['targetMajor'] : null,
                    'installed' => [],
                    'fixedVersions' => [],
                    'projects' => [],
                    'fix' => $this->fixes->forFinding($finding),
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

        $issues = $this->groupRepeatedMessages(array_values($groups));
        usort($issues, fn (array $first, array $second): int => [$first['severity']->rank(), -count($first['projects'])] <=> [$second['severity']->rank(), -count($second['projects'])]);

        return [
            'topIssues' => array_map(fn (array $issue): array => [
                ...$issue,
                'severity' => $issue['severity']->value,
                'installed' => array_values($issue['installed']),
                'fixedVersions' => array_values($issue['fixedVersions']),
                'projects' => array_values($issue['projects']),
                'packages' => array_map(fn (array $member): array => [
                    'key' => $member['key'],
                    'package' => $member['package'],
                    'projects' => array_values($member['projects']),
                ], $issue['packages']),
            ], array_slice($issues, 0, self::TOP_ISSUES_LIMIT)),
            'topIssuesTotal' => count($issues),
        ];
    }

    /**
     * Merges the package groups that share a check id and a message into one row (#291): the
     * worst severity, every affected project, and the packages listed under `packages`. Only the
     * display is grouped: each finding keeps its own state. A row that stands alone keeps its
     * fields and an empty `packages`.
     *
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function groupRepeatedMessages(array $issues): array
    {
        $issuesByMessage = [];

        foreach ($issues as $issue) {
            $issuesByMessage[$issue['checkId'].'|'.$issue['message']][] = $issue;
        }

        return array_map(function (array $members): array {
            if (count($members) === 1) {
                return [...$members[0], 'packages' => []];
            }

            // The package that affects most projects first, then by name.
            usort($members, fn (array $one, array $other): int => [-count($one['projects']), $one['package']] <=> [-count($other['projects']), $other['package']]);
            $first = $members[0];
            $worst = $first['severity'];

            foreach ($members as $member) {
                if ($member['severity']->rank() < $worst->rank()) {
                    $worst = $member['severity'];
                }
            }

            return [
                ...$first,
                'key' => 'group|'.$first['checkId'].'|'.$first['message'],
                'severity' => $worst,
                'package' => '',
                'advisoryId' => null,
                'evidenceUrl' => null,
                'installed' => [],
                'fixedVersions' => [],
                // Keyed by slug, so a project affected through several packages counts once.
                'projects' => array_replace(...array_column($members, 'projects')),
                'packages' => $members,
                'fix' => null,
            ];
        }, array_values($issuesByMessage));
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

            foreach ($this->health->runtimes($project->latestReport->report) as $name => $runtime) {
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
                if (! $this->isPosture((string) ($finding['checkId'] ?? ''))) {
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
                    'fix' => $this->fixes->forFinding($finding),
                ];
            }
        }

        usort($rows, fn (array $first, array $second): int => (SeverityEnum::tryFrom($first['severity'])?->rank() ?? 9) <=> (SeverityEnum::tryFrom($second['severity'])?->rank() ?? 9));

        return $rows;
    }

    /** Open exception groups listed on the dashboard. */
    public const TOP_EXCEPTIONS_LIMIT = 10;

    /**
     * Runtime and exceptions across the scoped projects for the period (#24, #26): estimated
     * requests and 5xx, open exception groups (and how many first appeared in the period), and
     * the open groups seen most recently. Null blocks when no project pushes that data.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{runtime: array{requests: int, errors: int, previousRequests: int}|null, exceptions: array{open: int, new: int, top: list<array<string, mixed>>}|null}
     */
    private function telemetry(Collection $projects, CarbonImmutable $today, int $periodDays): array
    {
        $ids = $projects->pluck('id');
        $from = $today->subDays($periodDays);
        // The whole hours of a period from the hourly rollups (#137), the minutes of its partial
        // first and last hour from the aggregates, so the totals stay exact to the minute.
        $requests = function (CarbonImmutable $start, CarbonImmutable $end) use ($ids): array {
            $firstHour = $start->ceilHour();
            $lastHour = $end->startOfHour();

            if ($firstHour >= $lastHour) {
                return $this->requestSums(RuntimeAggregate::query()->toBase(), $ids, $start, $end);
            }

            $hours = $this->requestSums(RuntimeHourlyRollup::query()->toBase(), $ids, $firstHour, $lastHour);
            $head = $this->requestSums(RuntimeAggregate::query()->toBase(), $ids, $start, $firstHour);
            $tail = $this->requestSums(RuntimeAggregate::query()->toBase(), $ids, $lastHour, $end);

            return [$hours[0] + $head[0] + $tail[0], $hours[1] + $head[1] + $tail[1]];
        };

        $hasRuntime = RuntimeAggregate::query()->whereIn('project_id', $ids)->exists();
        $hasExceptions = ExceptionGroup::query()->whereIn('project_id', $ids)->exists();
        [$count, $errors] = $requests($from, $today->addMinute());
        [$previousCount] = $requests($from->subDays($periodDays), $from);
        $open = ExceptionGroup::query()->whereIn('project_id', $ids)->where('status', ExceptionStatusEnum::Open->value);
        $names = $projects->pluck('name', 'id');
        $slugs = $projects->pluck('slug', 'id');

        return [
            'runtime' => $hasRuntime ? [
                'requests' => (int) round($count),
                'errors' => (int) round($errors),
                'previousRequests' => (int) round($previousCount),
            ] : null,
            'exceptions' => $hasExceptions ? [
                'open' => (clone $open)->count(),
                'new' => (clone $open)->where('first_seen_at', '>=', $from)->count(),
                'top' => array_values((clone $open)->orderByDesc('last_seen_at')->limit(self::TOP_EXCEPTIONS_LIMIT)->get()
                    ->map(fn (ExceptionGroup $group): array => [
                        'id' => $group->id,
                        'class' => $group->class,
                        'message' => $group->message,
                        'count' => $group->count,
                        'lastSeen' => $group->last_seen_at->toIso8601String(),
                        'context' => $group->context_name,
                        'project' => ['slug' => $slugs[$group->project_id], 'name' => $names[$group->project_id]],
                    ])->all()),
            ] : null,
        ];
    }

    /**
     * Estimated requests and errors of the projects between two instants, summed in SQL from the
     * minute aggregates or the hourly rollups (both have the same columns).
     *
     * @param  Collection<int, mixed>  $ids
     * @return array{0: float, 1: float}
     */
    private function requestSums(Builder $query, Collection $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sum = $query
            ->whereIn('project_id', $ids)
            ->where('type', 'request')
            ->where('bucket', '>=', $from)
            ->where('bucket', '<', $to)
            ->selectRaw('sum(1.0 * count / sample_rate) as requests, sum(1.0 * error_count / sample_rate) as errors')
            ->first();

        return [(float) ($sum->requests ?? 0), (float) ($sum->errors ?? 0)];
    }
}
