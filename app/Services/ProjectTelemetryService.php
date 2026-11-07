<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExceptionStatusEnum;
use App\Enums\TimeRangeEnum;
use App\Http\Resources\ExceptionGroupResource;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\Project;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The exceptions and schedule tabs of a project over a time range (#113): the exception groups
 * seen in the range with their occurrences per chart bucket, and the scheduled tasks with their
 * runs per outcome. Every sum is computed in SQL (TimeSeriesService); run counts are estimated
 * real counts (`count / sample_rate`).
 */
class ProjectTelemetryService
{
    /** Outcomes of a scheduled-task run, as excubia-core sends them. */
    private const OUTCOMES = ['finished', 'failed', 'skipped'];

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
    public function exceptions(Project $project, ?TimeRangeEnum $range = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::instance(Date::now());
        $from = $range?->start($now);
        $groups = $project->exceptionGroups()
            ->when($from !== null, fn ($query) => $query
                ->where('last_seen', '>=', $from)
                ->withSum(['occurrences as range_count' => fn ($occurrences) => $occurrences->where('bucket', '>=', $from)], 'count'))
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [ExceptionStatusEnum::Open->value, ExceptionStatusEnum::Resolved->value])
            ->orderByDesc('last_seen')
            ->limit(100)
            ->get();
        if ($range === null || $from === null) {
            return array_values(ExceptionGroupResource::collection($groups)->resolve());
        }

        $timelines = $this->timeSeries->points(
            ExceptionOccurrence::query()->whereIn('exception_group_id', $groups->modelKeys())->where('bucket', '>=', $from)->toBase(),
            'bucket',
            ['count' => 'count'],
            $range,
            $now,
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
     * The scheduled tasks (#110) with their estimated runs per outcome in the range, and the runs
     * of all tasks per bucket and outcome for the chart.
     *
     * @return array{tasks: list<array<string, mixed>>, timeline: list<array<string, int|string>>}
     */
    public function schedule(Project $project, TimeRangeEnum $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::instance(Date::now());
        $runs = fn () => ScheduledTaskRun::query()->where('project_id', $project->id)->where('bucket', '>=', $range->start($now))->toBase();
        $sums = [];

        foreach (self::OUTCOMES as $outcome) {
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
                'runs' => [...array_fill_keys(self::OUTCOMES, 0), ...($byTask[$task->task_id] ?? [])],
            ];
        }

        return ['tasks' => $tasks, 'timeline' => $this->timeSeries->points($runs(), 'bucket', $sums, $range, $now)];
    }
}
