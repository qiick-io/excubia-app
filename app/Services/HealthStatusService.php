<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\RuntimeTypeEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Carbon\CarbonImmutable;

/**
 * The live health of a project (#115), from the minute-level `health` samples of the last
 * WINDOW_MINUTES: per check and server, the samples of each minute merged (releases and sample
 * rates apart) and the readings that are not `skipped`, newest first. A `skipped` sample has no
 * reading: it is never read as a failure or as a recovery.
 *
 * A server's status is its latest reading; a check's status is the worst of its servers'. A
 * check starts failing when one server's last FAILING_READINGS readings all fail (debounce),
 * and recovers when no server's latest reading fails any more. A server that stopped sending
 * keeps its last reading, so silence is never a recovery.
 *
 * ponytail: a server that stops while failing holds the recovery back until it leaves the
 * window; a staleness alert of its own (core's suggestion) is the upgrade path.
 *
 * @phpstan-type HealthServer array{server: string, status: string, severity: string, findingCount: int, fingerprints: list<string>, lastSampleAt: string, readings: list<string>}
 * @phpstan-type HealthCheck array{checkId: string, status: string, severity: string, findingCount: int, fingerprints: list<string>, lastSampleAt: string, servers: list<HealthServer>}
 */
class HealthStatusService
{
    public const WINDOW_MINUTES = 60;

    /** Consecutive failing readings of one server before `health.failed`. */
    public const FAILING_READINGS = 2;

    /** A server without a sample for this long is shown as stale. */
    public const STALE_MINUTES = 5;

    /**
     * The checks with samples in the window, failing first, then by check id.
     *
     * @return list<HealthCheck>
     */
    public function checks(Project $project, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $samples = RuntimeAggregate::query()
            ->where('project_id', $project->id)
            ->where('type', RuntimeTypeEnum::Health->value)
            ->where('bucket', '>=', $now->subMinutes(self::WINDOW_MINUTES))
            ->orderByDesc('bucket')
            ->get(['dimensions', 'bucket', 'metrics']);

        /** @var array<string, array<string, array<string, array<string, mixed>>>> $minutes check id → server → bucket → merged metrics */
        $minutes = [];

        foreach ($samples as $sample) {
            $checkId = (string) ($sample->dimensions['checkId'] ?? '');
            $server = (string) ($sample->dimensions['server'] ?? '');
            $bucket = $sample->bucket->toIso8601ZuluString();
            $minutes[$checkId][$server][$bucket] = RuntimeTypeEnum::Health->mergeMetrics($minutes[$checkId][$server][$bucket] ?? [], $sample->metrics ?? []);
        }

        $checks = [];

        foreach ($minutes as $checkId => $servers) {
            ksort($servers);
            $rows = [];

            foreach ($servers as $server => $buckets) {
                $readings = array_values(array_filter($buckets, fn (array $metrics): bool => ($metrics['status'] ?? 'skipped') !== 'skipped'));
                $rows[] = [
                    'server' => (string) $server,
                    ...$this->reading($readings[0] ?? $buckets[array_key_first($buckets)] ?? []),
                    // Buckets are newest first, from the query order.
                    'lastSampleAt' => (string) array_key_first($buckets),
                    'readings' => array_map(fn (array $metrics): string => (string) $metrics['status'], $readings),
                ];
            }

            $checks[] = [
                'checkId' => (string) $checkId,
                ...$this->reading(array_reduce($rows, fn (array $merged, array $row): array => RuntimeTypeEnum::Health->mergeMetrics($merged, $row), [])),
                // ISO 8601 UTC strings sort like the times they hold.
                'lastSampleAt' => array_reduce($rows, fn (string $latest, array $row): string => max($latest, $row['lastSampleAt']), ''),
                'servers' => $rows,
            ];
        }

        usort($checks, fn (array $left, array $right): int => [array_search($left['status'], RuntimeTypeEnum::HEALTH_STATUSES, true), $left['checkId']]
            <=> [array_search($right['status'], RuntimeTypeEnum::HEALTH_STATUSES, true), $right['checkId']]);

        return $checks;
    }

    /**
     * The live health for the project overview: checks() without the readings, each server
     * with `isStale` when its last sample is older than STALE_MINUTES.
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(Project $project, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return array_map(fn (array $check): array => [
            ...array_diff_key($check, ['fingerprints' => true]),
            'servers' => array_map(fn (array $server): array => [
                'server' => $server['server'],
                'status' => $server['status'],
                'lastSampleAt' => $server['lastSampleAt'],
                'isStale' => CarbonImmutable::parse($server['lastSampleAt'])->lessThan($now->subMinutes(self::STALE_MINUTES)),
            ], $check['servers']),
        ], $this->checks($project, $now));
    }

    /**
     * The alert a check's readings call for, given whether it is alerted as failing now:
     * `health.failed` when a server's last FAILING_READINGS readings all fail, `health.recovered`
     * when it was failing and no server's latest reading fails, otherwise none.
     *
     * @param  list<list<string>>  $readings  per server, the non-skipped statuses newest first
     */
    public function transition(array $readings, bool $isFailing): ?AlertEventEnum
    {
        $readings = array_values(array_filter($readings, fn (array $statuses): bool => $statuses !== []));

        if (! $isFailing) {
            foreach ($readings as $statuses) {
                $latest = array_slice($statuses, 0, self::FAILING_READINGS);

                if (count($latest) === self::FAILING_READINGS && array_unique($latest) === ['fail']) {
                    return AlertEventEnum::HealthFailed;
                }
            }

            return null;
        }

        $isStillFailing = array_any($readings, fn (array $statuses): bool => $statuses[0] === 'fail');

        return $readings !== [] && ! $isStillFailing ? AlertEventEnum::HealthRecovered : null;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{status: string, severity: string, findingCount: int, fingerprints: list<string>}
     */
    private function reading(array $metrics): array
    {
        return [
            'status' => is_string($metrics['status'] ?? null) ? $metrics['status'] : 'skipped',
            'severity' => is_string($metrics['severity'] ?? null) ? $metrics['severity'] : SeverityEnum::Info->value,
            'findingCount' => (int) ($metrics['findingCount'] ?? 0),
            'fingerprints' => array_values(array_filter((array) ($metrics['fingerprints'] ?? []), 'is_string')),
        ];
    }
}
