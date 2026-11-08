<?php

declare(strict_types=1);

use App\Enums\AlertEventEnum;
use App\Enums\RuntimeTypeEnum;
use App\Services\HealthStatusService;

/**
 * The health merge of core `$defs.health` (#115) and the debounce of the health alerts.
 */
it('merges health metrics: worst status, most serious severity, larger count, capped sorted union', function () {
    $fingerprints = fn (string $from, int $count): array => array_map(fn (int $i): string => str_pad(dechex($i), 64, $from, STR_PAD_LEFT), range(1, $count));

    expect(RuntimeTypeEnum::Health->mergeMetrics(
        ['status' => 'pass', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => []],
        ['status' => 'skipped', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => []],
    ))->toBe(['status' => 'pass', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => []]);

    expect(RuntimeTypeEnum::Health->mergeMetrics(
        ['status' => 'warn', 'severity' => 'critical', 'findingCount' => 3, 'fingerprints' => ['b', 'a']],
        ['status' => 'fail', 'severity' => 'low', 'findingCount' => 1, 'fingerprints' => ['a', 'c']],
    ))->toBe(['status' => 'fail', 'severity' => 'critical', 'findingCount' => 3, 'fingerprints' => ['a', 'b', 'c']]);

    $merged = RuntimeTypeEnum::Health->mergeMetrics(['fingerprints' => $fingerprints('1', 15)], ['fingerprints' => $fingerprints('2', 15)]);

    expect($merged['fingerprints'])->toHaveCount(RuntimeTypeEnum::HEALTH_FINGERPRINTS)
        ->and($merged['fingerprints'])->toBe(array_slice(array_values(collect([...$fingerprints('1', 15), ...$fingerprints('2', 15)])->sort()->all()), 0, 20))
        ->and(RuntimeTypeEnum::Health->mergeMetrics([], ['status' => 'skipped'])['status'])->toBe('skipped');
});

it('debounces failure and recovery', function (array $readings, bool $isFailing, ?AlertEventEnum $expected) {
    expect((new HealthStatusService)->transition($readings, $isFailing))->toBe($expected);
})->with([
    'one failing reading' => [[['fail', 'pass']], false, null],
    'two failing readings' => [[['fail', 'fail', 'pass']], false, AlertEventEnum::HealthFailed],
    'two failing readings on one of two servers' => [[['pass', 'pass'], ['fail', 'fail']], false, AlertEventEnum::HealthFailed],
    'failing on two servers once each' => [[['fail', 'pass'], ['fail', 'pass']], false, null],
    'still failing' => [[['fail', 'fail']], true, null],
    'first pass after failing' => [[['pass', 'fail', 'fail']], true, AlertEventEnum::HealthRecovered],
    'warn after failing' => [[['warn', 'fail']], true, AlertEventEnum::HealthRecovered],
    'another server still fails' => [[['pass'], ['fail', 'fail']], true, null],
    'no readings at all' => [[[], []], true, null],
    'healthy and not failing' => [[['pass', 'pass']], false, null],
]);

it('calls a server stale only when every check it sent is stale', function () {
    $server = fn (string $name, string $lastSampleAt, bool $isStale): array => [
        'server' => $name, 'status' => 'pass', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => [],
        'lastSampleAt' => $lastSampleAt, 'isStale' => $isStale, 'readings' => ['pass'],
    ];
    $check = fn (string $checkId, array $servers): array => [
        'checkId' => $checkId, 'status' => 'pass', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => [], 'lastSampleAt' => '', 'servers' => $servers,
    ];

    expect((new HealthStatusService)->staleServers([
        // web-1 stopped one check only; worker-1 stopped everything.
        $check('laravel-cache', [$server('web-1', '2026-11-07T09:40:00Z', true), $server('worker-1', '2026-11-07T09:50:00Z', true)]),
        $check('laravel-database', [$server('web-1', '2026-11-07T10:00:00Z', false), $server('worker-1', '2026-11-07T09:51:00Z', true)]),
    ]))->toBe(['worker-1' => '2026-11-07T09:51:00Z']);
});

it('collects the sorted fingerprints of the failing servers only', function () {
    $server = fn (string $status, array $fingerprints): array => [
        'server' => 'web-1', 'status' => $status, 'severity' => 'info', 'findingCount' => count($fingerprints), 'fingerprints' => $fingerprints,
        'lastSampleAt' => '2026-11-07T10:00:00Z', 'isStale' => false, 'readings' => [$status],
    ];

    expect((new HealthStatusService)->failingFingerprints([$server('fail', ['c', 'a']), $server('warn', ['w']), $server('fail', ['a', 'b'])]))
        ->toBe(['a', 'b', 'c']);
});
