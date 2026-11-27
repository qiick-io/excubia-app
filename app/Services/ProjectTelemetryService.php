<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TimeRangeData;
use App\Enums\ExceptionStatusEnum;
use App\Http\Resources\ExceptionGroupResource;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\Project;
use App\Models\ScheduledTaskRun;

/**
 * The exceptions and schedule tabs of a project over a time range (#113): the exception groups
 * seen in the range with their occurrences per chart bucket, and the scheduled tasks with their
 * runs per outcome. Every sum is computed in SQL (TimeSeriesService); run counts are estimated
 * real counts (`count / sample_rate`).
 */
class ProjectTelemetryService
{
    public function __construct(private readonly TimeSeriesService $timeSeries) {}

    /**
     * The project's exception groups, open first, then the latest. With a range: only the groups
     * seen in it, each with `range_count` (its occurrences in the range) and `timeline` (one
     * `{at, count}` point per bucket); without one (the overview), every group as stored.
     *
     * ponytail: the 100 most relevant groups; paginate if a project keeps more.
     *
     * @return list<array<string, mixed>>
     */
    public function exceptions(Project $project, ?TimeRangeData $range = null): array
    {
        $from = $range?->from;
        $until = $range?->until;
        $groups = $project->exceptionGroups()
            ->with('assignee:id,first_name,last_name')
            ->when($from !== null, fn ($query) => $query
                ->where('last_seen_at', '>=', $from)
                ->where('first_seen_at', '<=', $until)
                ->withSum(['occurrences as range_count' => fn ($occurrences) => $occurrences->where('bucket', '>=', $from)->where('bucket', '<=', $until)], 'count'))
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [ExceptionStatusEnum::Open->value, ExceptionStatusEnum::Resolved->value])
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();
        if ($range === null || $from === null) {
            return array_values(ExceptionGroupResource::collection($groups)->resolve());
        }

        $timelines = $this->timeSeries->points(
            ExceptionOccurrence::query()->whereIn('exception_group_id', $groups->modelKeys())->where('bucket', '>=', $from)->where('bucket', '<=', $range->until)->toBase(),
            'bucket',
            ['count' => 'count'],
            $range,
            'exception_group_id',
            array_values($groups->modelKeys()),
        );

        return array_values($groups->map(fn (ExceptionGroup $group): array => [
            ...(new ExceptionGroupResource($group))->resolve(),
            'range_count' => (int) $group->range_count,
            'timeline' => $timelines[$group->id],
        ])->all());
    }

    /**
     * The exceptions of the overview's Application section (#250): occurrences of all groups per
     * bucket of the range, and the five groups seen most in it.
     *
     * @return array{timeline: list<array{at: string, count: int}>, top: list<array<string, mixed>>}
     */
    public function exceptionsOverview(Project $project, TimeRangeData $range): array
    {
        /** @var list<array{at: string, count: int}> $timeline */
        $timeline = $this->timeSeries->points(
            ExceptionOccurrence::query()
                ->whereIn('exception_group_id', $project->exceptionGroups()->select('id'))
                ->where('bucket', '>=', $range->from)
                ->where('bucket', '<=', $range->until)
                ->toBase(),
            'bucket',
            ['count' => 'count'],
            $range,
        );
        $top = array_values(collect($this->exceptions($project, $range))
            ->filter(fn (array $group): bool => $group['range_count'] > 0)
            ->sortByDesc('range_count')
            ->take(5)
            ->map(fn (array $group): array => array_diff_key($group, ['timeline' => true]))
            ->all());

        return ['timeline' => $timeline, 'top' => $top];
    }

    /**
     * The scheduled tasks (#110) with their estimated runs per outcome in the range, and the runs
     * of all tasks per bucket and outcome for the chart.
     *
     * @return array{tasks: list<array<string, mixed>>, timeline: list<array<string, int|string>>}
     */
    public function schedule(Project $project, TimeRangeData $range): array
    {
        $runs = fn () => ScheduledTaskRun::query()->where('project_id', $project->id)->where('bucket', '>=', $range->from)->where('bucket', '<=', $range->until)->toBase();
        $sums = [];

        foreach (ScheduledTaskRun::OUTCOMES as $outcome) {
            $sums[$outcome] = "case when outcome = '{$outcome}' then 1.0 * count / sample_rate else 0 end";
        }

        $byTask = [];

        foreach ($runs()->select('task_id', 'outcome')->selectRaw('sum(1.0 * count / sample_rate) as runs')->groupBy('task_id', 'outcome')->get() as $row) {
            $byTask[(string) $row->task_id][(string) $row->outcome] = (int) round((float) $row->runs);
        }

        $tasks = [];

        foreach ($project->scheduledTasks()->get() as $task) {
            $tasks[] = [
                ...(new ScheduledTaskResource($task))->resolve(),
                'runs' => [...array_fill_keys(ScheduledTaskRun::OUTCOMES, 0), ...($byTask[$task->task_id] ?? [])],
            ];
        }

        return ['tasks' => $tasks, 'timeline' => $this->timeSeries->points($runs(), 'bucket', $sums, $range)];
    }
}
