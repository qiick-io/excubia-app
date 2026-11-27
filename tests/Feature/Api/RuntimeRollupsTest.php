<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Models\Project;
use App\Models\RuntimeHourlyRollup;
use App\Services\DashboardOverviewService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Hourly runtime rollups (#137): filled at ingest with the additive merge of the minute
 * aggregates, backfilled once, pruned with the runtime retention, and read by the dashboard
 * totals, which stay exact to the minute.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-05 12:30:00');
});

/**
 * Pushes one runtime batch of request (or other type) entries for the project.
 *
 * @param  list<array<string, mixed>>  $entries  bucket, count, errorCount and optional overrides
 */
function pushRollupBatch(Project $project, array $entries, ?string $batchId = null): void
{
    $token = app(RotateProjectTokenAction::class)->execute($project);

    test()->postJson(route('api.runtime.store'), [
        'protocolVersion' => 1,
        'type' => 'runtime',
        'batchId' => $batchId ?? (string) Str::uuid(),
        'sentAt' => '2026-11-05T12:29:00Z',
        'runtime' => ['bucketSeconds' => 60, 'aggregates' => array_map(fn (array $entry): array => [
            'type' => 'request', 'sampleRate' => 1, 'route' => 'home', 'method' => 'GET', 'statusClass' => '2xx', 'errorCount' => 0, 'totalMs' => 10, 'maxMs' => 5,
            ...$entry,
        ], $entries)],
    ], ['Authorization' => "Bearer {$token}"])->assertSuccessful();
}

/**
 * @return list<array{type: string, bucket: string, sample_rate: float, count: int, error_count: int, total_ms: float|null}>
 */
function storedRollups(): array
{
    return RuntimeHourlyRollup::query()->orderBy('type')->orderBy('bucket')->orderBy('sample_rate')->get()
        ->map(fn (RuntimeHourlyRollup $rollup): array => [
            'type' => $rollup->type->value,
            'bucket' => $rollup->bucket->toIso8601ZuluString(),
            'sample_rate' => $rollup->sample_rate,
            'count' => $rollup->count,
            'error_count' => $rollup->error_count,
            'total_ms' => $rollup->total_ms,
        ])->all();
}

it('sums the pushed minutes per type, hour and sample rate, additive across batches', function () {
    $project = Project::factory()->create();
    $batchId = (string) Str::uuid();

    pushRollupBatch($project, [
        ['bucket' => '2026-11-05T11:58:00Z', 'count' => 4, 'totalMs' => 100],
        ['bucket' => '2026-11-05T11:59:00Z', 'statusClass' => '5xx', 'count' => 2, 'errorCount' => 2, 'totalMs' => 50],
        ['bucket' => '2026-11-05T11:59:00Z', 'sampleRate' => 0.5, 'count' => 1],
        ['bucket' => '2026-11-05T12:01:00Z', 'count' => 3],
        ['type' => 'logLevel', 'bucket' => '2026-11-05T12:01:00Z', 'level' => 'error', 'channel' => 'stack', 'count' => 7, 'errorCount' => 0],
        ['type' => 'notAType', 'bucket' => '2026-11-05T12:01:00Z', 'count' => 99],
    ], $batchId);
    // A repeated batch adds nothing; a new one adds to the same hour.
    pushRollupBatch($project, [['bucket' => '2026-11-05T11:58:00Z', 'count' => 4, 'totalMs' => 100]], $batchId);
    pushRollupBatch($project, [['bucket' => '2026-11-05T11:10:00Z', 'count' => 5, 'errorCount' => 1, 'totalMs' => 40]]);

    expect(storedRollups())->toBe([
        ['type' => 'logLevel', 'bucket' => '2026-11-05T12:00:00Z', 'sample_rate' => 1.0, 'count' => 7, 'error_count' => 0, 'total_ms' => null],
        ['type' => 'request', 'bucket' => '2026-11-05T11:00:00Z', 'sample_rate' => 0.5, 'count' => 1, 'error_count' => 0, 'total_ms' => 10.0],
        ['type' => 'request', 'bucket' => '2026-11-05T11:00:00Z', 'sample_rate' => 1.0, 'count' => 11, 'error_count' => 3, 'total_ms' => 190.0],
        ['type' => 'request', 'bucket' => '2026-11-05T12:00:00Z', 'sample_rate' => 1.0, 'count' => 3, 'error_count' => 0, 'total_ms' => 10.0],
    ]);
});

it('backfills the rollups from the stored aggregates, the same as ingest', function () {
    $project = Project::factory()->create();
    pushRollupBatch($project, [
        ['bucket' => '2026-11-05T11:58:00Z', 'count' => 4, 'totalMs' => 100],
        ['bucket' => '2026-11-05T11:59:00Z', 'statusClass' => '5xx', 'count' => 2, 'errorCount' => 2],
        ['bucket' => '2026-11-04T08:15:00Z', 'sampleRate' => 0.25, 'count' => 1],
        ['type' => 'logLevel', 'bucket' => '2026-11-05T12:01:00Z', 'level' => 'error', 'channel' => 'stack', 'count' => 7, 'errorCount' => 0],
    ]);
    $ingested = storedRollups();

    RuntimeHourlyRollup::query()->delete();
    $this->artisan('excubia:backfill-runtime-rollups')->expectsOutputToContain('Stored 3 hourly runtime rollups.')->assertSuccessful();
    // Running it again rebuilds rather than adds.
    $this->artisan('excubia:backfill-runtime-rollups')->assertSuccessful();

    expect(storedRollups())->toBe($ingested);
});

it('prunes the rollups with the runtime retention', function () {
    config(['excubia.runtime_retention_days' => 14]);
    $project = Project::factory()->create();
    pushRollupBatch($project, [
        ['bucket' => '2026-10-01T10:00:00Z', 'count' => 1],
        ['bucket' => '2026-11-04T10:00:00Z', 'count' => 2],
    ]);

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect(RuntimeHourlyRollup::query()->pluck('count')->all())->toBe([2]);
});

it('totals the dashboard requests exactly at the partial first and last hours', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    pushRollupBatch($project, [
        // Current period: 2026-10-22 12:30 up to now (12:30), previous one the 14 days before.
        ['bucket' => '2026-10-22T12:29:00Z', 'count' => 1000],
        ['bucket' => '2026-10-22T12:30:00Z', 'count' => 1],
        ['bucket' => '2026-10-22T12:59:00Z', 'count' => 2],
        ['bucket' => '2026-10-30T09:00:00Z', 'sampleRate' => 0.5, 'count' => 4, 'errorCount' => 1],
        ['bucket' => '2026-11-05T12:30:00Z', 'statusClass' => '5xx', 'count' => 3, 'errorCount' => 3],
        ['bucket' => '2026-10-08T12:29:00Z', 'count' => 5000],
        ['bucket' => '2026-10-08T12:30:00Z', 'count' => 10],
        ['bucket' => '2026-10-15T00:00:00Z', 'count' => 20],
        ['type' => 'job', 'bucket' => '2026-10-30T09:00:00Z', 'job' => 'App\\Jobs\\Ship', 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'processed', 'count' => 70],
    ]);
    pushRollupBatch($other, [['bucket' => '2026-10-30T09:00:00Z', 'count' => 300]]);

    $runtime = app(DashboardOverviewService::class)->overview(Project::query()->whereKey($project->id)->with('latestReport')->get(), 14)['runtime'];

    expect($runtime)->toBe(['requests' => 14, 'errors' => 5, 'previousRequests' => 1030]);
});
