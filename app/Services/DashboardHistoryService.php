<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TimeRangeData;
use App\Enums\ExceptionStatusEnum;
use App\Enums\RuntimeTypeEnum;
use App\Models\ExceptionGroup;
use App\Models\ExceptionOpenChange;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Thirty UTC days of dashboard history. Report and inbox numbers are snapshots carried
 * forward until they change. Request volume and latency come from the samples of each day.
 *
 * @phpstan-type History array{values: list<float|int|null>, previous: float|int|null}
 * @phpstan-type RequestWindow array{requests: int, errors: int, averageMs: float|null}
 * @phpstan-type RequestHistory array{requests: History, latency: History}
 * @phpstan-type ExceptionHistory array{current: int, previous: int|null, values: list<int>}
 */
class DashboardHistoryService
{
    public const DAYS = 30;

    public function __construct(
        private readonly FindingsTrendService $trends,
        private readonly SettingsRepositoryService $settings,
    ) {}

    /** @return array{findings: History, checks: History} */
    public function reports(Project $project, CarbonImmutable $now): array
    {
        $from = $now->utc()->startOfDay()->subDays(self::DAYS);
        $reports = $this->trends->snapshots([$project->id], $from, $now, shouldIncludeChecks: true);
        $findings = ['values' => [], 'previous' => null];
        $checks = ['values' => [], 'previous' => null];
        $findingsCount = $checkCount = null;
        $reportIndex = 0;

        for ($day = $from; $day->lte($now); $day = $day->addDay()) {
            while (isset($reports[$reportIndex]) && $reports[$reportIndex]->received_at->lt($day->addDay())) {
                $report = $reports[$reportIndex++];
                $findingsCount = $report->critical_count + $report->high_count + $report->medium_count + $report->low_count + $report->info_count;
                $checkCount = (int) $report->getAttribute('snapshot_pass_count');
            }

            if ($day->eq($from)) {
                $findings['previous'] = $findingsCount;
                $checks['previous'] = $checkCount;

                continue;
            }

            $findings['values'][] = $findingsCount;
            $checks['values'][] = $checkCount;
        }

        return ['findings' => $findings, 'checks' => $checks];
    }

    /**
     * The current open count and a reverse replay of count changes among retained groups.
     * History before the first tracked transition is unknown on an upgraded installation.
     *
     * @param  list<int>  $projectIds
     * @return ExceptionHistory
     */
    public function exceptions(array $projectIds, CarbonImmutable $now, int $comparisonDays = self::DAYS): array
    {
        $today = $now->utc()->startOfDay();
        $historyFrom = $today->subDays(self::DAYS);
        $comparisonDay = $today->subDays($comparisonDays);
        $from = $historyFrom->min($comparisonDay);
        $changes = ExceptionOpenChange::query()->whereIn('project_id', $projectIds)->where('occurred_at', '<=', $now)->toBase();
        $firstChange = (clone $changes)->min('occurred_at');
        $current = ExceptionGroup::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', ExceptionStatusEnum::Open->value)
            ->selectRaw('null as day, count(*) as amount');

        // One statement sees the current count and transitions in the same database snapshot.
        $changesByDay = $changes->where('occurred_at', '>=', $from)
            ->selectRaw('date(occurred_at) as day, sum(delta) as amount')
            ->groupByRaw('date(occurred_at)')
            ->unionAll($current)
            ->pluck('amount', 'day');
        $open = $count = (int) $changesByDay->get('');
        $previous = null;
        $newestFirst = [];

        if ($firstChange === null) {
            return ['current' => $open, 'previous' => null, 'values' => []];
        }

        $earliest = CarbonImmutable::parse((string) $firstChange, 'UTC')->startOfDay()->max($from);

        for ($day = $today; $day->gte($earliest); $day = $day->subDay()) {
            if ($day->eq($comparisonDay)) {
                $previous = $count;
            }

            if ($day->gt($historyFrom)) {
                $newestFirst[] = $count;
            }

            $count -= (int) $changesByDay->get($day->toDateString());
        }

        return [
            'current' => $open,
            'previous' => $previous,
            'values' => array_reverse($newestFirst),
        ];
    }

    /**
     * Completed UTC days keep a partial day from looking like a traffic drop.
     * A missing day is unknown; a recorded day with zero requests stays zero.
     *
     * @param  list<int>  $projectIds
     * @return RequestHistory
     */
    public function requests(array $projectIds, CarbonImmutable $now, ?TimeRangeData $range = null): array
    {
        $today = $now->utc()->startOfDay();
        $from = $today->subDays(self::DAYS);
        // Retention is applied at an instant. Its first calendar day may be incomplete.
        $firstCompleteDay = $now->utc()->subDays($this->settings->runtimeRetentionDays())->ceilDay();
        $daily = $this->requestQuery($projectIds, $from->max($firstCompleteDay), $today, true)
            ->selectRaw('date(bucket) as day, sum(1.0 * count / sample_rate) as requests, sum(count) as samples, sum(total_ms) as duration')
            ->groupByRaw('date(bucket)')
            ->get()
            ->keyBy('day');
        $previous = null;

        if ($range !== null) {
            // The runtime summary includes the first aligned bucket and the last observed
            // minute, so compare an immediately preceding window of the same length.
            $minutesEnd = $range->until->startOfMinute()->addMinute();
            $seconds = $minutesEnd->getTimestamp() - $range->from->getTimestamp();
            $previous = $this->previousRequestWindow($projectIds, $range->from->subSeconds($seconds), $range->from, $now);
        }

        $requests = ['values' => [], 'previous' => $previous['requests'] ?? null];
        $latency = ['values' => [], 'previous' => $previous['averageMs'] ?? null];

        for ($day = $from; $day->lt($today); $day = $day->addDay()) {
            $point = $daily->get($day->toDateString());
            $requests['values'][] = $point === null ? null : (int) round((float) $point->requests);
            $latency['values'][] = $point === null ? null : $this->average((int) $point->samples, $point->duration === null ? null : (float) $point->duration);
        }

        return ['requests' => $requests, 'latency' => $latency];
    }

    /**
     * A partially retained period would make the current requests or latency delta misleading.
     *
     * @param  list<int>  $projectIds
     * @return RequestWindow|null
     */
    public function previousRequestWindow(array $projectIds, CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $now): ?array
    {
        return $from->lt($now->subDays($this->settings->runtimeRetentionDays()))
            ? null
            : $this->requestWindow($projectIds, $from, $until);
    }

    /**
     * Half-open window. Use hourly rollups for whole hours and minute rows at the edges.
     * Estimates scale by each sample rate. Average latency matches the runtime reporting
     * calculation by dividing total measured milliseconds by the measured request count.
     *
     * @param  list<int>  $projectIds
     * @return RequestWindow|null
     */
    public function requestWindow(array $projectIds, CarbonImmutable $from, CarbonImmutable $until): ?array
    {
        $firstHour = $from->ceilHour();
        $lastHour = $until->startOfHour();
        $wholeHours = $firstHour->lt($lastHour);
        $windows = $wholeHours
            ? [[$from, $firstHour, false], [$firstHour, $lastHour, true], [$lastHour, $until, false]]
            : [[$from, $until, false]];
        $requests = $errors = $samples = 0;
        $duration = null;
        $hasData = false;

        foreach ($windows as [$start, $end, $usesRollups]) {
            if ($start->gte($end)) {
                continue;
            }

            $totals = $this->requestQuery($projectIds, $start, $end, $usesRollups)
                ->selectRaw('sum(1.0 * count / sample_rate) as requests, sum(1.0 * error_count / sample_rate) as errors, sum(count) as samples, sum(total_ms) as duration')
                ->first();

            if ($totals === null || $totals->requests === null) {
                continue;
            }

            $hasData = true;
            $requests += (float) $totals->requests;
            $errors += (float) $totals->errors;
            $samples += (int) $totals->samples;

            if ($totals->duration !== null) {
                $duration = ($duration ?? 0.0) + (float) $totals->duration;
            }
        }

        return $hasData ? ['requests' => (int) round($requests), 'errors' => (int) round($errors), 'averageMs' => $this->average($samples, $duration)] : null;
    }

    /** @param list<int> $projectIds */
    private function requestQuery(array $projectIds, CarbonImmutable $from, CarbonImmutable $until, bool $usesRollups): Builder
    {
        return ($usesRollups ? RuntimeHourlyRollup::query() : RuntimeAggregate::query())->toBase()
            ->whereIn('project_id', $projectIds)
            ->where('type', RuntimeTypeEnum::Request->value)
            ->where('bucket', '>=', $from)
            ->where('bucket', '<', $until);
    }

    private function average(int $count, ?float $duration): ?float
    {
        return $count > 0 && $duration !== null ? round($duration / $count, 1) : null;
    }
}
