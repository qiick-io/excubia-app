<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query behind the projects list (#293): the projects the user may view, each with the
 * health of its latest report (the worst check status), its failed and run checks, and its open
 * findings per severity, so the list answers "which project do I open first?".
 *
 * Every column is a subquery of the one list query, so a page costs one query however many rows
 * it has (no N+1). The open findings are the active ones SidebarProjectsService counts; the
 * check columns are the summary stored with each report (ReportHealthService::checkSummary()).
 */
final class ProjectListService
{
    /**
     * Columns the list sorts by. `health` and `open_findings` are computed below.
     *
     * @var list<string>
     */
    public const array SORTABLE_COLUMNS = ['name', 'health', 'open_findings', 'last_report_at', 'created_at'];

    /**
     * Health ranks, worst first; a project without a report, or whose checks were all skipped,
     * ranks after a passing one.
     *
     * @var array<string, int>
     */
    public const array HEALTH_RANKS = ['fail' => 0, 'warn' => 1, 'pass' => 2];

    /**
     * HEALTH_RANKS as SQL, 3 for unknown. `min()` makes the subquery return a row (null, then 3)
     * for a project without any report; without it there would be no row and a null rank, which
     * the drivers sort differently.
     */
    private const string HEALTH_RANK_SQL = "coalesce(min(case worst_check_status when 'fail' then 0 when 'warn' then 1 when 'pass' then 2 end), 3)";

    /**
     * Visible projects with `health_rank`, `checks_count`, `failed_checks_count` (of the latest
     * report) and `open_<severity>_count` (active findings).
     *
     * @return Builder<Project>
     */
    public function query(?User $user): Builder
    {
        $latestHealthRank = HealthReport::query()
            ->selectRaw(self::HEALTH_RANK_SQL)
            ->where('health_reports.id', '=', $this->latestReportColumn()->select('id'));

        $openFindingCounts = [];

        foreach (SeverityEnum::cases() as $severity) {
            $openFindingCounts["findings as open_{$severity->value}_count"] = fn (Builder $findings): Builder => $findings
                // @phpstan-ignore method.notFound (local scope of Finding)
                ->active()
                ->where('severity', $severity->value);
        }

        return Project::query()
            ->visibleTo($user)
            ->select('projects.*')
            ->selectSub($latestHealthRank, 'health_rank')
            ->selectSub($this->latestReportColumn()->select('checks_count'), 'checks_count')
            ->selectSub($this->latestReportColumn()->select('failed_checks_count'), 'failed_checks_count')
            ->withCount($openFindingCounts);
    }

    /**
     * Orders the list. Health ascending puts failing projects first; open findings descending
     * puts the most critical findings first, then the most high ones, and so on.
     *
     * @param  Builder<Project>  $projects
     * @param  'asc'|'desc'  $direction
     * @return Builder<Project>
     */
    public function sort(Builder $projects, string $column, string $direction): Builder
    {
        if ($column === 'health') {
            $projects->orderBy('health_rank', $direction)->orderBy('name');
        } elseif ($column === 'open_findings') {
            foreach (SeverityEnum::cases() as $severity) {
                $projects->orderBy("open_{$severity->value}_count", $direction);
            }

            $projects->orderBy('name');
        } else {
            $projects->orderBy($column, $direction);
        }

        return $projects->orderBy('projects.id');
    }

    /**
     * A column of the project's latest report, for a correlated subquery. Same order as
     * Project::latestReport(), with the id breaking a tie.
     *
     * @return Builder<HealthReport>
     */
    private function latestReportColumn(): Builder
    {
        return HealthReport::query()
            ->whereColumn('health_reports.project_id', 'projects.id')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(1);
    }
}
