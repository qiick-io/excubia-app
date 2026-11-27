<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ExceptionInboxData;
use App\Data\TimeRangeData;
use App\Enums\ExceptionStatusEnum;
use App\Http\Resources\ExceptionGroupResource;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOccurrence;
use App\Models\Project;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

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
                ->withSum(['occurrences as range_count' => fn ($occurrences) => $occurrences->whereBetween('bucket', [$from, $until])], 'count'))
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [ExceptionStatusEnum::Open->value, ExceptionStatusEnum::Resolved->value])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        if ($range === null || $from === null) {
            return array_values(ExceptionGroupResource::collection($groups)->resolve());
        }

        return $this->exceptionRows($groups, $range);
    }

    /** @return array{groups: array<string, mixed>, filters: array<string, mixed>, counts: array{all: int, open: int, mine: int, new: int}} */
    public function exceptionInbox(Project $project, TimeRangeData $range, ExceptionInboxData $filters, int $userId, CarbonImmutable $now): array
    {
        $cutoff = $now->subDay();
        $inRange = $project->exceptionGroups()->getQuery()
            ->where('last_seen_at', '>=', $range->from)
            ->where('first_seen_at', '<=', $range->until);
        $open = ExceptionStatusEnum::Open->value;
        $counts = (clone $inRange)->toBase()
            ->selectRaw('count(*) as all_groups, sum(case when status = ? then 1 else 0 end) as open_groups, sum(case when status = ? and assigned_user_id = ? then 1 else 0 end) as mine, sum(case when status = ? and first_seen_at >= ? then 1 else 0 end) as new_groups', [$open, $open, $userId, $open, $cutoff])
            ->first();

        $query = $this->withExceptionRangeCount($inRange->select('exception_groups.*'), $range)
            ->when($filters->status !== null, fn (Builder $groups) => $groups->where('exception_groups.status', $filters->status))
            ->when($filters->assignedUserId !== null, fn (Builder $groups) => $groups->where('assigned_user_id', $filters->assignedUserId))
            ->when($filters->isNewOnly, fn (Builder $groups) => $groups->where('first_seen_at', '>=', $cutoff));

        if ($filters->search !== '') {
            $pattern = '%'.strtr($filters->search, ['!' => '!!', '_' => '!_', '%' => '!%']).'%';
            $text = match ($query->getModel()->getConnection()->getDriverName()) {
                'mysql', 'mariadb' => "concat(class, ' ', message, ' ', context_name)",
                default => "class || ' ' || message || ' ' || context_name",
            };
            $query->whereRaw("lower({$text}) like lower(?) escape '!'", [$pattern]);
        }

        if ($filters->assigneeIds !== [] || $filters->shouldIncludeUnassigned) {
            $query->where(fn (Builder $groups) => $groups
                ->whereHas('assignee', fn (Builder $users) => $users->whereKey($filters->assigneeIds))
                ->when($filters->shouldIncludeUnassigned, fn (Builder $groups) => $groups->orWhereDoesntHave('assignee')));
        }

        if ($filters->assigneeSortDirection !== null) {
            $direction = $filters->assigneeSortDirection;
            $query->leftJoin('users as assignees', fn (JoinClause $join) => $join
                ->on('assignees.id', '=', 'exception_groups.assigned_user_id')
                ->whereNull('assignees.deleted_at'))
                ->orderByRaw('case when assignees.id is null then 1 else 0 end '.($direction === 'desc' ? 'desc' : 'asc'))
                ->orderBy('assignees.first_name', $direction)
                ->orderBy('assignees.last_name', $direction);
        }

        $groups = $query
            ->orderByRaw('case exception_groups.status when ? then 0 when ? then 1 else 2 end', [$open, ExceptionStatusEnum::Resolved->value])
            ->orderByDesc('exception_groups.last_seen_at')
            ->orderByDesc('exception_groups.id')
            ->paginate($filters->perPage)->withQueryString();

        return [
            'groups' => [...$groups->toArray(), 'data' => $this->exceptionRows(new Collection($groups->items()), $range)],
            'filters' => $filters->filters(),
            'counts' => ['all' => (int) $counts?->all_groups, 'open' => (int) $counts?->open_groups, 'mine' => (int) $counts?->mine, 'new' => (int) $counts?->new_groups],
        ];
    }

    /**
     * A deep link may point at a group beyond the active page, time range or filter.
     *
     * @return array<string, mixed>|null
     */
    public function linkedException(Project $project, TimeRangeData $range, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $rows = $this->withExceptionRangeCount($project->exceptionGroups()->getQuery(), $range)->whereKey($id)->get();

        return $this->exceptionRows($rows, $range)[0] ?? null;
    }

    /**
     * @param  Builder<ExceptionGroup>  $groups
     * @return Builder<ExceptionGroup>
     */
    private function withExceptionRangeCount(Builder $groups, TimeRangeData $range): Builder
    {
        return $groups->with('assignee:id,first_name,last_name')
            ->withSum(['occurrences as range_count' => fn ($occurrences) => $occurrences->whereBetween('bucket', [$range->from, $range->until])], 'count');
    }

    /**
     * @param  Collection<int, ExceptionGroup>  $groups
     * @return list<array<string, mixed>>
     */
    private function exceptionRows(Collection $groups, TimeRangeData $range): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        $timelines = $this->timeSeries->points(
            ExceptionOccurrence::query()->whereIn('exception_group_id', $groups->modelKeys())->whereBetween('bucket', [$range->from, $range->until])->toBase(),
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
