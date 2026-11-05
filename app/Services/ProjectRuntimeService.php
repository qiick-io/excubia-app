<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The runtime of a project over the last hours (#24), from the aggregates excubia-core pushes:
 * request totals and per route, an hourly request series, slow queries, jobs, scheduled tasks
 * and outgoing HTTP per host. Counts are estimated real counts (`count / sample_rate`);
 * `isSampled` tells the page to show them as approximate. Durations are the recorded ones.
 */
class ProjectRuntimeService
{
    /** Rows per table, so the page stays light. */
    public const TABLE_LIMIT = 20;

    /**
     * @return array<string, mixed>|null null when the project never pushed runtime data
     */
    public function summary(Project $project, int $hours = 24): ?array
    {
        if (! $project->runtimeAggregates()->exists()) {
            return null;
        }

        $now = CarbonImmutable::instance(Date::now());
        $from = $now->subHours($hours)->startOfHour();

        /** @var Collection<int, RuntimeAggregate> $rows */
        $rows = $project->runtimeAggregates()->where('bucket', '>=', $from)->get();
        $byType = $rows->groupBy(fn (RuntimeAggregate $row): string => $row->type->value);
        $requests = $byType->get(RuntimeTypeEnum::Request->value, collect());

        return [
            'hours' => $hours,
            'isSampled' => $rows->contains(fn (RuntimeAggregate $row): bool => $row->sample_rate < 1),
            'requests' => [
                ...$this->totals($requests),
                'hourly' => $this->hourly($requests, $from, $now),
                'routes' => $this->table($requests, ['route', 'method']),
            ],
            'slowQueries' => $this->table($byType->get(RuntimeTypeEnum::SlowQuery->value, collect()), ['sql', 'connection']),
            'jobs' => $this->table($byType->get(RuntimeTypeEnum::Job->value, collect()), ['job', 'queue']),
            'scheduledTasks' => $this->table($byType->get(RuntimeTypeEnum::ScheduledTask->value, collect()), ['task']),
            'outgoingHttp' => $this->table($byType->get(RuntimeTypeEnum::OutgoingHttp->value, collect()), ['host']),
        ];
    }

    /**
     * @param  Collection<int, RuntimeAggregate>  $rows
     * @return array{count: int, errors: int, averageMs: float|null, maxMs: float|null}
     */
    private function totals(Collection $rows): array
    {
        $recorded = $rows->sum('count');

        return [
            'count' => (int) round($rows->sum(fn (RuntimeAggregate $row): float => $row->count / $row->sample_rate)),
            'errors' => (int) round($rows->sum(fn (RuntimeAggregate $row): float => $row->error_count / $row->sample_rate)),
            'averageMs' => $recorded > 0 ? round($rows->sum('total_ms') / $recorded, 1) : null,
            'maxMs' => $rows->isEmpty() ? null : round((float) $rows->max('max_ms'), 1),
        ];
    }

    /**
     * Estimated requests per hour, split in successful and failed (5xx), one point per hour.
     *
     * @param  Collection<int, RuntimeAggregate>  $rows
     * @return list<array{hour: string, ok: int, errors: int}>
     */
    private function hourly(Collection $rows, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $byHour = $rows->groupBy(fn (RuntimeAggregate $row): string => $row->bucket->copy()->utc()->startOfHour()->toIso8601ZuluString());
        $series = [];

        for ($hour = $from; $hour->lte($now); $hour = $hour->addHour()) {
            $bucket = $byHour->get($hour->toIso8601ZuluString(), collect());
            $total = $this->totals($bucket);
            $series[] = ['hour' => $hour->toIso8601ZuluString(), 'ok' => $total['count'] - $total['errors'], 'errors' => $total['errors']];
        }

        return $series;
    }

    /**
     * Rows grouped by the given dimensions, the busiest first, with the outcome split for jobs
     * and scheduled tasks.
     *
     * @param  Collection<int, RuntimeAggregate>  $rows
     * @param  list<string>  $dimensions
     * @return list<array<string, mixed>>
     */
    private function table(Collection $rows, array $dimensions): array
    {
        return array_values($rows
            ->groupBy(fn (RuntimeAggregate $row): string => implode("\0", array_map(fn (string $dimension): string => $row->dimensions[$dimension] ?? '', $dimensions)))
            ->map(function (Collection $group) use ($dimensions): array {
                /** @var RuntimeAggregate $first */
                $first = $group->first();
                $outcomes = $group->groupBy(fn (RuntimeAggregate $row): string => $row->dimensions['outcome'] ?? '')
                    ->filter(fn (Collection $rows, string $outcome): bool => $outcome !== '')
                    ->map(fn (Collection $rows): int => (int) round($rows->sum(fn (RuntimeAggregate $row): float => $row->count / $row->sample_rate)));

                return [
                    ...array_combine($dimensions, array_map(fn (string $dimension): string => $first->dimensions[$dimension] ?? '', $dimensions)),
                    ...$this->totals($group),
                    'outcomes' => $outcomes->all(),
                    'lastAt' => $group->max('bucket')?->toIso8601ZuluString(),
                ];
            })
            ->sortByDesc('count')
            ->take(self::TABLE_LIMIT)
            ->all());
    }
}
