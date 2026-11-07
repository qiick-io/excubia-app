<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeverityEnum;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The Fleet pages: upgrade readiness (#19), advisory exposure (#20) and the end-of-life calendar
 * (#23) across the projects a user may see, each read from the latest stored report of every
 * project. No new data source: package names, versions, ids and dates as excubia-core sends them.
 *
 * @phpstan-type ProjectRef array{slug: string, name: string}
 */
class FleetService
{
    private const READINESS_RANK = ['blocked' => 0, 'ready' => 1, 'unknown' => 2];

    public function __construct(private ReportHealthService $health) {}

    /**
     * One row per project (blocked first, most blockers first, then ready, then unknown) and,
     * per Laravel major projects could move to, how many of them can move today: "7 of 12
     * projects can move to Laravel 14 today".
     *
     * @param  Collection<int, Project>  $projects  with `latestReport` loaded
     * @return array{targets: list<array{targetMajor: int, ready: int, total: int}>, unknown: int, projects: list<array<string, mixed>>}
     */
    public function upgradeReadiness(Collection $projects): array
    {
        $rows = [];
        $targets = [];

        foreach ($projects as $project) {
            $readiness = $project->latestReport === null
                ? ['laravelVersion' => null, 'targetMajor' => null, 'status' => 'unknown', 'blockers' => [], 'uncheckedPackages' => 0]
                : $this->health->upgradeReadiness($project->latestReport->report);
            $rows[] = [
                'project' => $this->reference($project),
                ...$readiness,
                'hasReport' => $project->latestReport !== null,
                'planSteps' => count((array) ($project->latestReport->report['upgradePlan'] ?? [])),
            ];

            if ($readiness['status'] !== 'unknown') {
                $target = (int) $readiness['targetMajor'];
                $targets[$target] ??= ['targetMajor' => $target, 'ready' => 0, 'total' => 0];
                $targets[$target]['total']++;
                $targets[$target]['ready'] += $readiness['status'] === 'ready' ? 1 : 0;
            }
        }

        ksort($targets);
        usort($rows, fn (array $first, array $second): int => [self::READINESS_RANK[$first['status']], -count($first['blockers']), $first['project']['name']]
            <=> [self::READINESS_RANK[$second['status']], -count($second['blockers']), $second['project']['name']]);

        return [
            'targets' => array_values($targets),
            'unknown' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'unknown')),
            'projects' => $rows,
        ];
    }

    /**
     * Package → projects (#20): one row per check, package and advisory, with every project
     * exposed and its locked version, fixed versions and severity. Worst first, then by the
     * number of projects. The page searches by package or advisory id.
     *
     * @param  Collection<int, Project>  $projects  with `latestReport` loaded
     * @return list<array<string, mixed>>
     */
    public function advisoryExposure(Collection $projects): array
    {
        $groups = [];

        foreach ($projects as $project) {
            if ($project->latestReport === null) {
                continue;
            }

            foreach ($this->health->packageRisks($project->latestReport->report) as $risk) {
                $key = implode('|', [$risk['checkId'], $risk['package'], $risk['advisoryId'] ?? '']);
                $groups[$key] ??= [
                    'key' => $key,
                    'kind' => $risk['kind'],
                    'ecosystem' => $risk['ecosystem'],
                    'package' => $risk['package'],
                    'advisoryId' => $risk['advisoryId'],
                    'evidenceUrl' => $risk['evidenceUrl'],
                    'replacement' => $risk['replacement'],
                    'severity' => $risk['severity'],
                    'projects' => [],
                ];

                if (SeverityEnum::from($risk['severity'])->rank() < SeverityEnum::from($groups[$key]['severity'])->rank()) {
                    $groups[$key]['severity'] = $risk['severity'];
                }

                $groups[$key]['projects'][] = [
                    ...$this->reference($project),
                    'lockedVersion' => $risk['lockedVersion'],
                    'fixedVersions' => $risk['fixedVersions'],
                    'severity' => $risk['severity'],
                ];
            }
        }

        $groups = array_values($groups);
        usort($groups, fn (array $first, array $second): int => [SeverityEnum::from($first['severity'])->rank(), -count($first['projects']), $first['package']]
            <=> [SeverityEnum::from($second['severity'])->rank(), -count($second['projects']), $second['package']]);

        return $groups;
    }

    /**
     * One row per project with its PHP, Laravel and Node support windows (#23), sorted by the
     * nearest end of security support (ended first); runtimes without any date are left out,
     * and so are projects without one.
     *
     * @param  Collection<int, Project>  $projects  with `latestReport` loaded
     * @return list<array{project: ProjectRef, nearestEndsOn: string|null, runtimes: list<array<string, mixed>>}>
     */
    public function endOfLifeCalendar(Collection $projects): array
    {
        $rows = [];

        foreach ($projects as $project) {
            if ($project->latestReport === null) {
                continue;
            }

            $runtimes = [];

            foreach ($this->health->runtimes($project->latestReport->report) as $name => $runtime) {
                if ($runtime['securitySupportEndsOn'] !== null || $runtime['activeSupportEndsOn'] !== null) {
                    $runtimes[] = ['name' => $name, ...$runtime];
                }
            }

            if ($runtimes === []) {
                continue;
            }

            $ends = array_filter(array_map(fn (array $runtime): ?string => $runtime['securitySupportEndsOn'] ?? $runtime['activeSupportEndsOn'], $runtimes));
            $rows[] = ['project' => $this->reference($project), 'nearestEndsOn' => $ends === [] ? null : min($ends), 'runtimes' => $runtimes];
        }

        usort($rows, fn (array $first, array $second): int => [$first['nearestEndsOn'] ?? '9999-12-31', $first['project']['name']]
            <=> [$second['nearestEndsOn'] ?? '9999-12-31', $second['project']['name']]);

        return $rows;
    }

    /**
     * @return ProjectRef
     */
    private function reference(Project $project): array
    {
        return ['slug' => $project->slug, 'name' => $project->name];
    }
}
