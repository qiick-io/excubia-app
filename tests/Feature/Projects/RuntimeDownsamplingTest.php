<?php

declare(strict_types=1);

use App\Actions\DownsampleRuntimeAction;
use App\Actions\PruneTelemetryAction;
use App\Actions\StorePushedRuntimeAction;
use App\Enums\TimeRangeEnum;
use App\Models\Project;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use App\Services\AiUsageService;
use App\Services\ProjectRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Downsampling of the runtime minutes (#212): minutes older than the per-minute window fold into
 * one row per series and hour, and every card, table, percentile and chart of the runtime and AI
 * tabs reads the same before and after the fold, on SQLite, MySQL and PostgreSQL.
 */
beforeEach(function () {
    Date::setTestNow('2026-11-08 12:30:00');
    Cache::forget(DownsampleRuntimeAction::FOLDED_UNTIL_KEY);
    $this->project = Project::factory()->create();
});

/**
 * One minute of a busy app, as excubia-core pushes it, varied by the minute: some request minutes
 * without a histogram, a sampled job series, changing gauges, a failing health check, AI usage
 * with and without optional counters, and an untimed Scout search next to timed index jobs.
 *
 * @return list<array<string, mixed>>
 */
function downsamplingMinute(CarbonImmutable $bucket): array
{
    $minute = (int) $bucket->format('i');
    $at = $bucket->toIso8601ZuluString();
    $base = fn (int $count, int $errors = 0, float $sampleRate = 1): array => ['bucket' => $at, 'sampleRate' => $sampleRate, 'count' => $count, 'errorCount' => $errors];
    $histogram = fn (int $index, int $count): array => array_replace(array_fill(0, 12, 0), [$index => $count]);

    return [
        ['type' => 'request', ...$base(10 + $minute, $minute % 7 === 0 ? 1 : 0), 'route' => 'home', 'method' => 'GET', 'statusClass' => '2xx', 'totalMs' => 40.5 * (10 + $minute), 'maxMs' => 100 + $minute,
            ...($minute % 3 === 0 ? [] : ['histogram' => $histogram($minute % 6 + 2, 10 + $minute)])],
        ['type' => 'request', ...$base(2), 'route' => 'orders.store', 'method' => 'POST', 'statusClass' => '5xx', 'totalMs' => 1800, 'maxMs' => 950 + $minute, 'histogram' => $histogram(7, 2)],
        ['type' => 'job', ...$base(4, $minute % 5 === 0 ? 1 : 0, 0.5), 'job' => 'App\\Jobs\\SyncStock', 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'processed', 'totalMs' => 800, 'maxMs' => 300 + $minute, 'histogram' => $histogram(5, 4)],
        ['type' => 'requestMemory', ...$base(10 + $minute), 'route' => 'home', 'method' => 'GET', 'totalMb' => 18.5 * (10 + $minute), 'maxMb' => 40 + $minute / 10],
        ['type' => 'cache', ...$base(50), 'store' => 'redis', 'keyPrefix' => 'users', 'hits' => 40 + $minute, 'misses' => $minute % 4, 'writes' => 3, 'deletes' => 0],
        ['type' => 'logLevel', ...$base(1 + $minute % 3), 'level' => $minute % 2 === 0 ? 'error' : 'info', 'channel' => 'stack'],
        ['type' => 'duplicateQuery', ...$base(2), 'origin' => 'request', 'name' => 'home', 'sql' => 'select * from users where id = ?', 'connection' => 'mysql', 'totalRepeats' => 10 + $minute, 'maxRepeats' => 3 + $minute % 9],
        ['type' => 'server', ...$base(1), 'server' => 'web-1', 'loadAverage1' => 0.5 + $minute / 100, 'loadAverage5' => 0.8, 'loadAverage15' => 0.6, 'cpuCores' => 4, 'cpuLimit' => null, 'memoryUsedMb' => 1000 + $minute, 'memoryTotalMb' => 4096, 'diskUsedGb' => 31.5, 'diskTotalGb' => 80, 'isContainer' => $minute === 17],
        ['type' => 'health', ...$base(1), 'server' => 'web-1', 'checkId' => 'laravel-database', 'status' => $minute === 23 ? 'fail' : 'pass', 'severity' => $minute === 23 ? 'high' : 'info', 'findingCount' => $minute === 23 ? 1 : 0, 'fingerprints' => $minute === 23 ? [str_repeat('a', 12)] : []],
        ['type' => 'ai', ...$base(2), 'operation' => 'agent', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'failoverCount' => 0, 'totalMs' => 4800, 'maxMs' => 2600 + $minute, 'histogram' => $histogram(9, 2),
            'inputTokens' => 1000 + $minute, 'outputTokens' => 200, 'cacheReadInputTokens' => 0, 'cacheWriteInputTokens' => 0, 'reasoningTokens' => $minute % 2 === 0 ? 50 : null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null],
        ['type' => 'scout', ...$base(3), 'model' => 'App\\Models\\Product', 'engine' => 'meilisearch', 'operation' => 'search', 'modelCount' => 0],
        ['type' => 'scout', ...$base(1), 'model' => 'App\\Models\\Product', 'engine' => 'meilisearch', 'operation' => 'index', 'modelCount' => 5, 'totalMs' => 30, 'maxMs' => 30, 'histogram' => $histogram(3, 1)],
        ['type' => 'pennant', ...$base(4), 'feature' => 'App\\Features\\NewCheckout', 'activeCount' => 3, 'inactiveCount' => 1, 'resolvedCount' => 0, 'unknownCount' => 0],
    ];
}

/** Pushes every `$step`-th minute from `$from` for `$minutes` minutes, one batch per minute. */
function pushDownsamplingMinutes(Project $project, CarbonImmutable $from, int $minutes, int $step = 1): void
{
    for ($offset = 0; $offset < $minutes; $offset += $step) {
        app(StorePushedRuntimeAction::class)->execute($project, (string) Str::uuid(), downsamplingMinute($from->addMinutes($offset)), 'v1.2.0');
    }
}

/**
 * The runtime and AI tabs of the project over the long ranges, as the page gets them.
 *
 * @return array<string, mixed>
 */
function downsampledViews(Project $project): array
{
    $project->refresh();
    $views = [];

    foreach ([TimeRangeEnum::Day, TimeRangeEnum::Week, TimeRangeEnum::TwoWeeks] as $range) {
        $views[$range->value] = [
            'runtime' => app(ProjectRuntimeService::class)->summary($project, $range),
            'ai' => app(AiUsageService::class)->summary($project, $range),
        ];
    }

    return $views;
}

it('folds old minutes into one row per series and hour with the same cards, percentiles and charts', function () {
    // Two whole hours and a partial one three days ago, a sparse hour ten days ago, and the
    // last hour, which stays in minutes.
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-05 09:00:00'), 150);
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-10-29 17:05:00'), 50, 7);
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-08 11:30:00'), 60);

    $before = downsampledViews($this->project);
    $rowsBefore = RuntimeAggregate::query()->count();
    $recentRows = RuntimeAggregate::query()->where('bucket', '>=', '2026-11-06 12:00:00')->count();

    $folded = app(DownsampleRuntimeAction::class)->execute(CarbonImmutable::now()->subHours(48));

    expect($folded)->toBeGreaterThan(0)
        ->and(RuntimeAggregate::query()->count())->toBe($rowsBefore - $folded)
        // 14 series (13 a minute, the log level alternates) over 4 hours: one row per series and hour.
        ->and(RuntimeAggregate::query()->where('bucket', '<', '2026-11-06 12:00:00')->count())->toBe(4 * 14)
        ->and(RuntimeAggregate::query()->where('bucket', '>=', '2026-11-06 12:00:00')->count())->toBe($recentRows)
        ->and(downsampledViews($this->project))->toEqual($before);

    // Re-running folds nothing and changes nothing.
    expect(app(DownsampleRuntimeAction::class)->execute(CarbonImmutable::now()->subHours(48)))->toBe(0)
        ->and(downsampledViews($this->project))->toEqual($before);
});

it('merges a folded hour by the read rules: nullable sums, maxima, histograms and the worst health', function () {
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-05 09:00:00'), 60);

    app(DownsampleRuntimeAction::class)->execute(CarbonImmutable::now()->subHours(48));

    $request = RuntimeAggregate::query()->where('type', 'request')->where('dimensions->route', 'home')->sole();
    $health = RuntimeAggregate::query()->where('type', 'health')->sole();
    $server = RuntimeAggregate::query()->where('type', 'server')->sole();
    $ai = RuntimeAggregate::query()->where('type', 'ai')->sole();
    $search = RuntimeAggregate::query()->where('type', 'scout')->where('dimensions->operation', 'search')->sole();

    // The hour keeps its latest minute, so `lastAt` does not move.
    expect($request->bucket->toIso8601ZuluString())->toBe('2026-11-05T09:59:00Z')
        ->and($request->count)->toBe(array_sum(range(10, 69)))
        ->and($request->max_ms)->toBe(159.0)
        // Only the minutes with a histogram (two of three) add to it.
        ->and(array_sum($request->histogram ?? []))->toBe(array_sum(array_filter(range(10, 69), fn (int $count): bool => ($count - 10) % 3 !== 0)))
        ->and($health->metrics)->toMatchArray(['status' => 'fail', 'severity' => 'high', 'findingCount' => 1, 'fingerprints' => [str_repeat('a', 12)]])
        ->and($server->metrics)->toMatchArray(['memoryUsedMb' => 1059, 'isContainer' => true, 'cpuLimit' => null])
        ->and($ai->metrics['reasoningTokens'])->toEqual(30 * 50)
        ->and($ai->metrics['imageInputTokens'])->toBeNull()
        ->and($search->total_ms)->toBeNull()
        ->and($search->histogram)->toBeNull();
});

it('keeps the minutes of the per-minute window and of the hour the cutoff falls in', function () {
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-06 12:00:00'), 60, 10);
    $rows = RuntimeAggregate::query()->count();

    // The cutoff 2026-11-06 12:30 falls inside that hour: it is folded on a later run.
    expect(app(DownsampleRuntimeAction::class)->execute(CarbonImmutable::now()->subHours(48)))->toBe(0)
        ->and(RuntimeAggregate::query()->count())->toBe($rows);
});

it('folds a late push into an hour folded before, within a day of the last run', function () {
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-06 10:00:00'), 60, 10);
    app(DownsampleRuntimeAction::class)->execute(CarbonImmutable::now()->subHours(48));

    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-06 10:05:00'), 1);
    $before = downsampledViews($this->project);

    // 12 series fold; the late minute's `info` log level is a series of its own in that hour.
    expect(app(DownsampleRuntimeAction::class)->execute(CarbonImmutable::now()->subHours(48)))->toBe(12)
        ->and(RuntimeAggregate::query()->where('type', 'request')->where('dimensions->route', 'home')->sole()->count)->toBe(10 + 20 + 30 + 40 + 50 + 60 + 15)
        ->and(downsampledViews($this->project))->toEqual($before);
});

it('runs hourly with a per-minute window of at least 24 hours', function () {
    pushDownsamplingMinutes($this->project, CarbonImmutable::parse('2026-11-07 08:00:00'), 60, 10);

    config(['excubia.runtime_minute_hours' => 48]);
    $this->artisan('excubia:downsample-runtime')->expectsOutputToContain('Folded 0 runtime minutes older than 48 hours')->assertSuccessful();

    config(['excubia.runtime_minute_hours' => 1]);
    $this->artisan('excubia:downsample-runtime')->expectsOutputToContain('Folded 65 runtime minutes older than 24 hours')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'excubia:downsample-runtime'));

    expect($event?->expression)->toBe('0 * * * *');
});

it('keeps the pushed batch ids for two weeks, whatever the exceptions retention', function () {
    foreach ([13, 15] as $daysAgo) {
        PushedBatch::query()->create(['project_id' => $this->project->id, 'type' => 'runtime', 'batch_id' => (string) Str::uuid(), 'received_at' => Date::now()->subDays($daysAgo)]);
    }

    app(PruneTelemetryAction::class)->execute(14, 90);

    expect(PushedBatch::query()->count())->toBe(1)
        ->and(PruneTelemetryAction::BATCH_ID_DAYS)->toBe(14);
});
