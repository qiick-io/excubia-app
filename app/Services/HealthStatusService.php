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
 * and recovers when no server's latest reading fails any more. A server without a sample of
 * the check for STALE_MINUTES is stale (#143): the alerts ignore it, and `health.stale` says
 * it went silent. When every server is stale nothing recovers: silence is never a recovery.
 *
 * @phpstan-type HealthServer array{server: string, status: string, severity: string, findingCount: int, fingerprints: list<string>, lastSampleAt: string, isStale: bool, readings: list<string>}
 * @phpstan-type HealthCheck array{checkId: string, status: string, severity: string, findingCount: int, fingerprints: list<string>, lastSampleAt: string, servers: list<HealthServer>}
 */
class HealthStatusService
{
    public const WINDOW_MINUTES = 60;

    /** Consecutive failing readings of one server before `health.failed`. */
    public const FAILING_READINGS = 2;

    /** A server without a sample for this long is stale: shown so, left out of the alerts, and `health.stale`. */
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
                    'isStale' => CarbonImmutable::parse((string) array_key_first($buckets))->lessThan($now->subMinutes(self::STALE_MINUTES)),
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
     * The live health for the project overview: checks() without the fingerprints and readings.
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(Project $project, ?CarbonImmutable $now = null): array
    {
        return array_map(fn (array $check): array => [
            ...array_diff_key($check, ['fingerprints' => true]),
            'servers' => array_map(fn (array $server): array => array_intersect_key($server, array_flip(['server', 'status', 'lastSampleAt', 'isStale'])), $check['servers']),
        ], $this->checks($project, $now));
    }

    /**
     * The live health of each project in the dashboard list (#143), keyed by slug, for the
     * projects with samples in the window: the worst check status, the number of checks and of
     * failing checks, and whether a server is stale.
     *
     * ponytail: one checks() query per project; one grouped query over every project's latest
     * samples is the upgrade path when a fleet makes the dashboard slow.
     *
     * @param  iterable<Project>  $projects
     * @return array<string, array{status: string, checks: int, failingChecks: int, hasStaleServers: bool}>
     */
    public function summaries(iterable $projects, ?CarbonImmutable $now = null): array
    {
        $summaries = [];

        foreach ($projects as $project) {
            $checks = $this->checks($project, $now);

            if ($checks === []) {
                continue;
            }

            $summaries[$project->slug] = [
                // checks() puts the worst status first.
                'status' => $checks[0]['status'],
                'checks' => count($checks),
                'failingChecks' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'fail')),
                'hasStaleServers' => array_any($checks, fn (array $check): bool => array_any($check['servers'], fn (array $server): bool => $server['isStale'])),
            ];
        }

        return $summaries;
    }

    /**
     * The servers stale on every check they sent in the window, with their latest sample: a
     * server that stopped sending, not one check turned off.
     *
     * @param  list<HealthCheck>  $checks  from checks()
     * @return array<string, string> server → its latest sample (ISO 8601 UTC)
     */
    public function staleServers(array $checks): array
    {
        $latest = [];
        $isStale = [];

        foreach ($checks as $check) {
            foreach ($check['servers'] as $server) {
                $latest[$server['server']] = max($latest[$server['server']] ?? '', $server['lastSampleAt']);
                $isStale[$server['server']] = ($isStale[$server['server']] ?? true) && $server['isStale'];
            }
        }

        return array_intersect_key($latest, array_filter($isStale));
    }

    /**
     * The sorted fingerprints of the servers whose latest reading fails: the failure a
     * `health.failed` names, and re-alerts on when it changes (#143).
     *
     * @param  list<HealthServer>  $servers
     * @return list<string>
     */
    public function failingFingerprints(array $servers): array
    {
        $fingerprints = [];

        foreach ($servers as $server) {
            if ($server['status'] === 'fail') {
                $fingerprints = [...$fingerprints, ...$server['fingerprints']];
            }
        }

        $fingerprints = array_values(array_unique($fingerprints));
        sort($fingerprints);

        return $fingerprints;
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
