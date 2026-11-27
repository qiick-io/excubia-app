<?php

declare(strict_types=1);

use App\Data\TimeRangeData;
use App\Enums\TimeRangeEnum;
use App\Models\Project;
use App\Models\RuntimeHourlyRollup;
use App\Services\DashboardHistoryService;
use App\Services\SettingsRepositoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('uses the latest report for each UTC day, carries snapshots forward and ignores future reports', function (): void {
    $project = Project::factory()->create();
    $now = CarbonImmutable::parse('2026-11-27T13:00:00Z');
    $store = function (CarbonImmutable $received, int $findings, int $passes) use ($project): void {
        $project->healthReports()->create([
            'batch_id' => Str::uuid()->toString(),
            'received_at' => $received,
            'generated_at' => $received,
            'high_count' => $findings,
            'report' => ['checks' => array_fill(0, $passes, ['status' => 'pass'])],
        ]);
    };

    $store($now->subDays(31), 12, 2);
    $store($now->subDays(2), 20, 1);
    $store($now->subDays(2), 5, 4);
    $store($now, 0, 5);
    $store($now->addHour(), 99, 99);

    $history = app(DashboardHistoryService::class)->reports($project, $now);

    expect($history['findings']['previous'])->toBe(12)
        ->and($history['checks']['previous'])->toBe(2)
        ->and($history['findings']['values'])->toHaveCount(30)
        ->and(array_slice($history['findings']['values'], -4))->toBe([12, 5, 5, 0])
        ->and(array_slice($history['checks']['values'], -4))->toBe([2, 4, 4, 5]);
});

it('replays the retained open-group changes without attributing history to another project', function (): void {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $now = CarbonImmutable::parse('2026-11-27T13:00:00Z');
    $group = function (Project $owner, string $status, CarbonImmutable $opened) use ($now) {
        $exception = $owner->exceptionGroups()->create([
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'environment' => 'production', 'class' => 'RuntimeException', 'message' => 'Failed',
            'frames' => [], 'previous' => [], 'context_kind' => 'request', 'context_name' => 'checkout',
            'count' => 1, 'first_seen_at' => $opened, 'last_seen_at' => $now, 'status' => $status,
        ]);
        $exception->openChanges()->create(['project_id' => $owner->id, 'occurred_at' => $opened, 'delta' => 1]);

        return $exception;
    };
    $group($project, 'open', $now->subDays(39));
    $closed = $group($project, 'resolved', $now->subDays(4));
    $closed->openChanges()->create(['project_id' => $project->id, 'occurred_at' => $now->subDays(2), 'delta' => -1]);
    $group($project, 'open', $now);
    $group($other, 'open', $now);

    $history = app(DashboardHistoryService::class)->exceptions([$project->id], $now);

    expect($history['current'])->toBe(2)
        ->and($history['previous'])->toBe(1)
        ->and($history['values'])->toHaveCount(30)
        ->and(array_slice($history['values'], -5))->toBe([2, 2, 1, 1, 2]);

    expect(app(DashboardHistoryService::class)->exceptions([$other->id], $now))
        ->toBe(['current' => 1, 'previous' => null, 'values' => [1]]);

    expect(app(DashboardHistoryService::class)->exceptions([$project->id], $now, 3))
        ->toBe(['current' => 2, 'previous' => 2, 'values' => $history['values']]);
});

it('uses the rollups for sampled traffic and observed latency and keeps missing days unknown', function (): void {
    $project = Project::factory()->create();
    $now = CarbonImmutable::parse('2026-11-27T13:00:00Z');
    $yesterday = $now->subDay()->startOfHour()->subHour();
    $store = fn (CarbonImmutable $bucket, float $sampleRate, int $count, ?float $totalMs) => RuntimeHourlyRollup::query()->create([
        'project_id' => $project->id, 'type' => 'request', 'bucket' => $bucket, 'sample_rate' => $sampleRate,
        'count' => $count, 'error_count' => 0, 'total_ms' => $totalMs,
    ]);
    $store($yesterday, 0.5, 4, 400.4);
    $store($yesterday, 1, 2, 900);
    $store($yesterday->subDays(2), 1, 0, 0);
    $store($now, 1, 100, 1000);

    $range = TimeRangeData::fromPreset(TimeRangeEnum::DEFAULT, $now);
    $history = app(DashboardHistoryService::class)->requests([$project->id], $now, $range);

    expect($history['requests']['values'])->toHaveCount(30)
        ->and(array_slice($history['requests']['values'], -3))->toBe([0, null, 10])
        ->and(array_slice($history['latency']['values'], -3))->toBe([null, null, 216.7])
        ->and($history['requests']['previous'])->toBe(10)
        ->and($history['latency']['previous'])->toBe(216.7);

    app(SettingsRepositoryService::class)->setMany(['runtime_retention_days' => 2]);
    $partial = app(DashboardHistoryService::class)->requests([$project->id], $now, $range);

    expect($partial['requests']['previous'])->toBeNull()
        ->and($partial['latency']['previous'])->toBeNull();
});

it('returns no comparison or observed values when the project has no data', function (): void {
    $project = Project::factory()->create();
    $now = CarbonImmutable::parse('2026-11-27T13:00:00Z');
    $history = app(DashboardHistoryService::class);
    $missing = ['values' => array_fill(0, 30, null), 'previous' => null];

    expect($history->reports($project, $now))->toBe(['findings' => $missing, 'checks' => $missing])
        ->and($history->requests([$project->id], $now))->toBe(['requests' => $missing, 'latency' => $missing])
        ->and($history->exceptions([$project->id], $now))->toBe(['current' => 0, 'previous' => null, 'values' => []]);
});
