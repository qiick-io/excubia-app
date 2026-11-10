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
 * Daily findings across projects for the dashboard trend: for each day, the severity counts
 * of every project's latest report received by the end of that day, summed. A project with no
 * report yet adds nothing.
 */
class FindingsTrendService
{
    /**
     * @param  Collection<int, Project>  $projects  the projects the user may see
     * @return list<array{date: string, critical: int, high: int, medium: int, low: int, info: int}>
     */
    public function daily(Collection $projects, int $days = 30): array
    {
        $today = CarbonImmutable::instance(Date::now())->startOfDay();
        $from = $today->subDays($days - 1);

        // Reports inside the window, plus the newest one before it so the first day is complete.
        $reports = HealthReport::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->where('received_at', '>=', $from)
            ->orderBy('received_at')
            ->get()
            // The last report before the period of each project, for the projects that have one.
            ->concat($projects->flatMap(function (Project $project) use ($from): array {
                $lastBefore = $project->healthReports()
                    ->where('received_at', '<', $from)
                    ->orderByDesc('received_at')
                    ->first();

                return $lastBefore === null ? [] : [$lastBefore];
            }))
            ->sortBy('received_at')
            ->values()
            ->all();

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
}
