<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Daily findings across projects for the dashboard trend: for each day, the severity counts
 * of every project's latest report received by the end of that day, summed. A project with no
 * report yet adds nothing.
 *
 * The trend starts at the first day with a report when that day falls inside the period, so a
 * new install does not draw a chart of mostly empty days (#295). With no report at all the
 * trend is empty.
 */
class FindingsTrendService
{
    /**
     * One point per day from the start of the period, or from the first day with a report when
     * that is later, up to today.
     *
     * @param  Collection<int, Project>  $projects  the projects the user may see
     * @return list<array{date: string, critical: int, high: int, medium: int, low: int, info: int}>
     */
    public function daily(Collection $projects, int $days = 30): array
    {
        $today = CarbonImmutable::instance(Date::now())->startOfDay();
        $from = $today->subDays($days - 1);

        $reports = $this->snapshots(array_values($projects->pluck('id')->all()), $from, CarbonImmutable::instance(Date::now()));
        $firstReport = $reports[0] ?? null;

        if ($firstReport === null) {
            return [];
        }

        $from = $from->max(CarbonImmutable::instance($firstReport->received_at)->startOfDay());

        $latestByProject = [];
        $trend = [];
        $cursor = 0;

        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            $endOfDay = $day->endOfDay();

            while ($cursor < count($reports) && $reports[$cursor]->received_at->lte($endOfDay)) {
                $latestByProject[$reports[$cursor]->project_id] = $reports[$cursor];
                $cursor++;
            }

            $counts = array_fill_keys(SeverityEnum::values(), 0);

            foreach ($latestByProject as $report) {
                foreach (SeverityEnum::values() as $severity) {
                    $counts[$severity] += (int) $report->{$severity.'_count'};
                }
            }

            $trend[] = ['date' => $day->toDateString(), ...$counts];
        }

        /** @var list<array{date: string, critical: int, high: int, medium: int, low: int, info: int}> $trend */
        return $trend;
    }

    /**
     * The last report on each day of the window and each project's baseline before it.
     * The correlated baseline lookup uses the project/time index and fetches all projects
     * together. Only the project KPI counts passing checks, with a JSON projection so the
     * original payloads (including package inventories) stay on the database server.
     *
     * @param  list<int>  $projectIds
     * @return list<HealthReport>
     */
    public function snapshots(array $projectIds, CarbonImmutable $from, CarbonImmutable $until, bool $shouldIncludeChecks = false): array
    {
        $ranked = HealthReport::query()
            ->whereIn('project_id', $projectIds)
            ->whereBetween('received_at', [$from, $until])
            ->selectRaw('id, row_number() over (partition by project_id, date(received_at) order by received_at desc, id desc) as daily_rank');
        $daily = DB::query()->fromSub($ranked, 'ranked_reports')->where('daily_rank', 1)->select('id');
        $previous = HealthReport::query()
            ->whereColumn('project_id', 'projects.id')
            ->where('received_at', '<', $from)
            ->latest('received_at')
            ->latest('id')
            ->limit(1)
            ->select('id');
        $baseline = Project::query()->whereKey($projectIds)->selectSub($previous, 'id')->toBase();
        $snapshots = HealthReport::query()
            ->select(['id', 'project_id', 'received_at', 'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count'])
            ->whereIn('id', $daily->unionAll($baseline))
            ->orderBy('received_at')
            ->orderBy('id');

        if ($shouldIncludeChecks) {
            $passCount = match ($snapshots->getModel()->getConnection()->getDriverName()) {
                'mysql', 'mariadb' => "coalesce(json_length(json_search(report, 'all', 'pass', null, '$.checks[*].status')), 0)",
                'pgsql' => <<<'SQL'
                    jsonb_array_length(jsonb_path_query_array(cast(report as jsonb), '$.checks[*] ? (@.status == "pass")'))
                    SQL,
                default => <<<'SQL'
                    (select count(*) from json_each(health_reports.report, '$.checks') as report_check
                        where json_extract(case when report_check.type = 'object' then report_check.value else '{}' end, '$.status') = 'pass')
                    SQL,
            };
            $snapshots->selectRaw($passCount.' as snapshot_pass_count');
        }

        return array_values($snapshots->get()->all());
    }
}
