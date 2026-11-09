<?php

declare(strict_types=1);

use App\Data\TimeRangeData;
use App\Enums\TimeRangeEnum;
use App\Models\Project;
use App\Services\DashboardOverviewService;
use App\Services\ProjectRuntimeService;
use App\Services\ProjectTelemetryService;
use App\Services\TimeSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Performance target of #113 and #137: the dashboard queries under 300 ms at 50 projects × 14 days.
 * Seeds 50 projects with a sample every 5 minutes for 14 days (4 request series, 2 with
 * histograms, 2 job series, 1 log series, 10 exception groups with hourly occurrences, 1
 * scheduled task), about 1.8 million rows, backfills their hourly rollups (#137), then times the
 * project tabs at every range and the home dashboard over 14 days, which must all stay under the
 * target. Slow to seed, so outside the default run:
 * `php artisan test --group=benchmark` (set DB_CONNECTION and DB_DATABASE for MySQL or PostgreSQL).
 */
const BENCHMARK_PROJECTS = 50;
const BENCHMARK_STEP_MINUTES = 5;
const BENCHMARK_TARGET_MS = 300;

/**
 * Inserts rows in chunks, so one statement stays below the bound-parameter limits.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function benchmarkInsert(string $table, array &$rows, bool $isFinal = false): void
{
    if ($rows !== [] && ($isFinal || count($rows) >= 500)) {
        DB::table($table)->insert($rows);
        $rows = [];
    }
}

function benchmarkSeed(CarbonImmutable $now): void
{
    $from = $now->subDays(14)->startOfDay();
    $histogram = json_encode([40, 30, 20, 10, 5, 3, 1, 1, 0, 0, 0, 0]);
    $series = [
        ['request', ['route' => 'home', 'method' => 'GET', 'statusClass' => '2xx'], true],
        ['request', ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx'], true],
        ['request', ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '5xx'], false],
        ['request', ['route' => 'api.orders', 'method' => 'GET', 'statusClass' => '2xx'], false],
        ['job', ['job' => 'App\\Jobs\\Ship', 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'processed'], true],
        ['job', ['job' => 'App\\Jobs\\Mail', 'queue' => 'mail', 'connection' => 'redis', 'outcome' => 'failed'], false],
        ['logLevel', ['level' => 'error', 'channel' => 'stack'], false],
    ];

    DB::disableQueryLog();

    for ($p = 1; $p <= BENCHMARK_PROJECTS; $p++) {
        $project = Project::factory()->create(['slug' => "project-{$p}"]);
        $taskId = str_repeat('c', 64);
        DB::table('scheduled_tasks')->insert(['project_id' => $project->id, 'task_id' => $taskId, 'task' => 'artisan reports:send', 'expression' => '*/5 * * * *', 'timezone' => 'UTC', 'environments' => '[]']);
        $aggregates = [];
        $runs = [];
        $occurrences = [];
        $groups = [];

        for ($g = 0; $g < 10; $g++) {
            $groups[] = DB::table('exception_groups')->insertGetId([
                'project_id' => $project->id, 'fingerprint' => hash('sha256', "{$p}-{$g}"), 'environment' => 'production', 'class' => 'RuntimeException',
                'message' => 'Failed', 'frames' => '[]', 'previous' => '[]', 'context_kind' => 'request', 'context_name' => 'home', 'count' => 336,
                'first_seen_at' => $from, 'last_seen_at' => $now, 'status' => 'open',
            ]);
        }

        for ($at = $from; $at->lte($now); $at = $at->addMinutes(BENCHMARK_STEP_MINUTES)) {
            foreach ($series as [$type, $dimensions, $hasHistogram]) {
                $isError = ($dimensions['statusClass'] ?? $dimensions['outcome'] ?? 'error') !== '2xx' && ($dimensions['outcome'] ?? '') !== 'processed';
                $aggregates[] = [
                    'project_id' => $project->id, 'type' => $type, 'bucket' => $at, 'sample_rate' => 1, 'dimension_key' => hash('sha256', (string) json_encode($dimensions)),
                    'dimensions' => json_encode($dimensions), 'count' => 110, 'error_count' => $isError ? 110 : 0, 'total_ms' => $type === 'logLevel' ? null : 5500.0, 'max_ms' => $type === 'logLevel' ? null : 900.0,
                    'metrics' => null, 'histogram' => $hasHistogram ? $histogram : null, 'release' => 'v1',
                ];
            }

            $runs[] = ['project_id' => $project->id, 'task_id' => $taskId, 'bucket' => $at, 'outcome' => 'finished', 'count' => 1, 'sample_rate' => 1];

            if ($at->minute === 0) {
                foreach ($groups as $group) {
                    $occurrences[] = ['project_id' => $project->id, 'exception_group_id' => $group, 'bucket' => $at, 'count' => 1];
                }
            }

            benchmarkInsert('runtime_aggregates', $aggregates);
            benchmarkInsert('scheduled_task_runs', $runs);
            benchmarkInsert('exception_occurrences', $occurrences);
        }

        benchmarkInsert('runtime_aggregates', $aggregates, true);
        benchmarkInsert('scheduled_task_runs', $runs, true);
        benchmarkInsert('exception_occurrences', $occurrences, true);
    }
}

/**
 * The slowest of three runs of a read, in milliseconds (the first run warms the caches).
 */
function benchmarkTime(callable $read): float
{
    $times = [];

    for ($i = 0; $i < 3; $i++) {
        $started = hrtime(true);
        $read();
        $times[] = (hrtime(true) - $started) / 1e6;
    }

    return round(max(array_slice($times, 1)), 1);
}

it('serves the dashboard and the runtime, exceptions and schedule tabs under 300 ms at 50 projects × 14 days', function () {
    Date::setTestNow('2026-11-05 12:30:00');
    $now = CarbonImmutable::instance(Date::now());
    benchmarkSeed($now);
    // The rows are inserted directly, so the hourly rollups come from the one-off backfill.
    $started = hrtime(true);
    test()->artisan('excubia:backfill-runtime-rollups')->assertSuccessful();
    $backfill = (hrtime(true) - $started) / 1e9;
    $project = Project::query()->where('slug', 'project-25')->sole();
    $results = [];
    $results['home dashboard 14d'] = benchmarkTime(fn () => app(DashboardOverviewService::class)->overview(Project::query()->with('latestReport')->get(), 14));

    foreach (TimeRangeEnum::cases() as $preset) {
        $range = TimeRangeData::fromPreset($preset, $now);
        $results["runtime {$preset->value}"] = benchmarkTime(fn () => app(ProjectRuntimeService::class)->summary($project, $range));
        $results["exceptions {$preset->value}"] = benchmarkTime(fn () => app(ProjectTelemetryService::class)->exceptions($project, $range));
        $results["schedule {$preset->value}"] = benchmarkTime(fn () => app(ProjectTelemetryService::class)->schedule($project, $range));
        $results["deploys {$preset->value}"] = benchmarkTime(fn () => app(TimeSeriesService::class)->deploys($project, $range));
    }

    $lines = ['', 'Benchmark #113 ('.DB::connection()->getDriverName().', '.DB::table('runtime_aggregates')->count().' runtime rows, '.DB::table('runtime_hourly_rollups')->count().' hourly rollups backfilled in '.round($backfill, 1).' s):'];

    foreach ($results as $read => $milliseconds) {
        $lines[] = sprintf('  %-22s %8.1f ms', $read, $milliseconds);
    }

    fwrite(STDOUT, implode(PHP_EOL, $lines).PHP_EOL);

    expect(max($results))->toBeLessThan(BENCHMARK_TARGET_MS);
})->group('benchmark');
