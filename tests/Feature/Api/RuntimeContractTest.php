<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Contract of `POST /api/runtime` with excubia-core (#111): one entry of every core runtime type,
 * with every optional field, is checked against core's `docs/runtime-schema.json` and must be
 * stored; a type the app does not know is skipped and counted, never rejected.
 *
 * `tests/Fixtures/core/runtime-schema.json` is a copy of excubia-core `develop` at
 * 8e9f346c454316453c7c33c6e92294cabfe2f239 (2026-11-08, core #208: `environment`). Refresh it when core adds a type.
 * The app has no JSON Schema validator, so coreSchemaErrors() (tests/Pest.php) checks the keywords
 * the runtime schema uses (required, additionalProperties, const, enum, type, pattern, min/max, lengths).
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-06 12:00:00');
});

/**
 * @return array<string, mixed>
 */
function coreRuntimeSchema(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/core/runtime-schema.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * One entry per core type, every optional field included.
 *
 * @return array<string, array<string, mixed>>
 */
function coreRuntimeAggregates(): array
{
    $base = ['bucket' => '2026-11-06T11:58:00Z', 'sampleRate' => 1, 'count' => 4, 'errorCount' => 1];
    $timed = ['totalMs' => 400.5, 'maxMs' => 250.25, 'histogram' => [0, 0, 1, 0, 1, 1, 1, 0, 0, 0, 0, 0]];
    $quiet = ['errorCount' => 0];

    return [
        'request' => ['type' => 'request', ...$base, 'route' => 'projects.show', 'method' => 'GET', 'statusClass' => '5xx', ...$timed],
        'slowQuery' => ['type' => 'slowQuery', ...$base, ...$quiet, 'sql' => 'select * from users where id = ?', 'connection' => 'pgsql', ...$timed],
        'job' => ['type' => 'job', ...$base, 'job' => 'App\\Jobs\\SendReport', 'queue' => '', 'connection' => 'redis', 'outcome' => 'failed', ...$timed],
        'scheduledTask' => ['type' => 'scheduledTask', ...$base, 'task' => 'artisan excubia:push', 'taskId' => str_repeat('ab', 32), 'outcome' => 'finished', ...$timed],
        'outgoingHttp' => ['type' => 'outgoingHttp', ...$base, 'host' => 'api.github.com', 'statusClass' => '2xx', ...$timed],
        'requestMemory' => ['type' => 'requestMemory', ...$base, ...$quiet, 'route' => 'unnamed', 'method' => 'POST', 'totalMb' => 120.5, 'maxMb' => 48.125],
        'jobMemory' => ['type' => 'jobMemory', ...$base, ...$quiet, 'job' => 'App\\Jobs\\SendReport', 'queue' => 'default', 'totalMb' => 80, 'maxMb' => 30.5],
        'queueWait' => ['type' => 'queueWait', ...$base, ...$quiet, 'job' => 'App\\Jobs\\SendReport', 'queue' => 'default', ...$timed],
        'cache' => ['type' => 'cache', ...$base, ...$quiet, 'store' => 'redis', 'keyPrefix' => '[masked]', 'hits' => 2, 'misses' => 1, 'writes' => 1, 'deletes' => 0],
        'mail' => ['type' => 'mail', ...$base, 'mailable' => 'unknown', 'mailer' => 'smtp'],
        'notification' => ['type' => 'notification', ...$base, 'notification' => 'App\\Notifications\\InvoicePaid', 'channel' => 'mail'],
        'logLevel' => ['type' => 'logLevel', ...$base, ...$quiet, 'level' => 'warning', 'channel' => 'stack'],
        'deprecation' => ['type' => 'deprecation', ...$base, ...$quiet, 'source' => 'vendor/laravel/framework', 'message' => 'Implicitly marking parameter $x as nullable is deprecated', 'phpVersion' => '8.4'],
        'duplicateQuery' => ['type' => 'duplicateQuery', ...$base, ...$quiet, 'origin' => 'request', 'name' => 'projects.index', 'sql' => 'select * from reports where project_id IN (…)', 'connection' => 'sqlite', 'totalRepeats' => 40, 'maxRepeats' => 15],
        'server' => ['type' => 'server', ...$base, ...$quiet, 'count' => 1, 'server' => 'a1b2c3d4e5f6', 'loadAverage1' => 0.52, 'loadAverage5' => 0.4, 'loadAverage15' => 0.3, 'cpuCores' => 8, 'cpuLimit' => 1.5, 'memoryUsedMb' => 900, 'memoryTotalMb' => 2048, 'diskUsedGb' => 20.5, 'diskTotalGb' => 80, 'isContainer' => true],
        'command' => ['type' => 'command', ...$base, 'command' => 'migrate', 'exitCodeClass' => 'other', ...$timed],
        'health' => ['type' => 'health', ...$base, 'count' => 1, 'server' => 'web-1', 'checkId' => 'laravel-database', 'status' => 'fail', 'severity' => 'critical', 'findingCount' => 1, 'fingerprints' => [str_repeat('c', 64)]],
        'ai' => [
            'type' => 'ai', ...$base, 'operation' => 'agent', 'agent' => 'App\\Ai\\SupportAgent', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5-20250929',
            'failoverCount' => 1, ...$timed, 'inputTokens' => 12000, 'outputTokens' => 800, 'cacheReadInputTokens' => 9000, 'cacheWriteInputTokens' => 1000,
            'reasoningTokens' => null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null,
        ],
        'aiTool' => ['type' => 'aiTool', ...$base, 'agent' => 'App\\Ai\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\LookupOrderTool', ...$timed],
    ];
}

/**
 * @param  list<array<string, mixed>>  $aggregates
 * @return array<string, mixed>
 */
function coreRuntimeEnvelope(array $aggregates, ?string $release = 'v1.4.0+build.7'): array
{
    $runtime = ['bucketSeconds' => 60, 'aggregates' => $aggregates];

    if ($release !== null) {
        $runtime = ['release' => $release, ...$runtime];
    }

    return ['protocolVersion' => 1, 'type' => 'runtime', 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-06T11:59:00Z', 'runtime' => $runtime];
}

/**
 * @return array{0: Project, 1: array<string, string>}
 */
function contractProject(): array
{
    $project = Project::factory()->create();

    return [$project, ['Authorization' => 'Bearer '.app(RotateProjectTokenAction::class)->execute($project)]];
}

it('knows every runtime type of the vendored core schema', function () {
    $schemaTypes = array_map(fn (array $entry): string => substr($entry['$ref'], strlen('#/$defs/')), coreRuntimeSchema()['properties']['aggregates']['items']['oneOf']);

    expect(RuntimeTypeEnum::values())->toEqualCanonicalizing($schemaTypes)
        ->and(array_keys(coreRuntimeAggregates()))->toEqualCanonicalizing($schemaTypes)
        ->and(coreRuntimeSchema()['$defs']['histogram']['minItems'])->toBe(RuntimeTypeEnum::HISTOGRAM_LENGTH);
});

it('builds test payloads that match the core runtime schema', function (string $type) {
    $schema = coreRuntimeSchema();
    $aggregate = coreRuntimeAggregates()[$type];

    expect(coreSchemaErrors($aggregate, $schema['$defs'][$type], $schema))->toBe([])
        ->and(coreSchemaErrors(coreRuntimeEnvelope([$aggregate])['runtime'], $schema, $schema))->toBe([]);

    // The checker catches what core would never send.
    expect(coreSchemaErrors([...$aggregate, 'bucket' => '2026-11-06T11:58:30Z'], $schema['$defs'][$type], $schema))->not->toBe([])
        ->and(coreSchemaErrors(array_diff_key($aggregate, ['count' => true]), $schema['$defs'][$type], $schema))->not->toBe([]);
})->with(RuntimeTypeEnum::values());

it('stores an entry of every core type with its dimensions, metrics and optional fields', function () {
    [$project, $headers] = contractProject();

    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope(array_values(coreRuntimeAggregates())), $headers)
        ->assertCreated()
        ->assertJson(['status' => 'stored']);

    $rows = $project->runtimeAggregates()->get()->keyBy(fn (RuntimeAggregate $row): string => $row->type->value);

    expect($rows->keys()->all())->toEqualCanonicalizing(RuntimeTypeEnum::values())
        ->and($rows->every(fn (RuntimeAggregate $row): bool => $row->release === 'v1.4.0+build.7'))->toBeTrue()
        ->and($project->fresh()?->skipped_runtime_aggregates)->toBe(0);

    foreach (RuntimeTypeEnum::cases() as $type) {
        $sent = coreRuntimeAggregates()[$type->value];
        $row = $rows[$type->value];

        expect($row->dimensions)->toEqual(array_map('strval', array_intersect_key($sent, array_flip([...$type->dimensions(), ...$type->optionalDimensions()]))))
            ->and($row->count)->toBe($sent['count'])
            ->and($row->error_count)->toBe($sent['errorCount'])
            ->and($row->total_ms)->toBe($type->isTimed() ? (float) $sent['totalMs'] : null)
            ->and($row->max_ms)->toBe($type->isTimed() ? (float) $sent['maxMs'] : null)
            ->and($row->histogram)->toBe($type->isTimed() ? $sent['histogram'] : null)
            ->and($row->metrics)->toEqual($type->metrics() === [] ? null : array_intersect_key($sent, $type->metrics()));
    }

    expect($rows['scheduledTask']->dimensions['taskId'])->toBe(str_repeat('ab', 32))
        ->and($rows['logLevel']->dimensions['channel'])->toBe('stack')
        ->and($rows['server']->metrics)->toMatchArray(['cpuLimit' => 1.5, 'isContainer' => true])
        ->and($rows['job']->dimensions['queue'])->toBe('');
});

it('merges entries of the same series across batches by each metric rule', function () {
    [$project, $headers] = contractProject();
    $aggregates = coreRuntimeAggregates();
    $second = [
        [...$aggregates['request'], 'count' => 2, 'errorCount' => 0, 'totalMs' => 100, 'maxMs' => 900, 'histogram' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1]],
        [...$aggregates['requestMemory'], 'count' => 1, 'totalMb' => 10, 'maxMb' => 90.5],
        [...$aggregates['cache'], 'count' => 3, 'hits' => 3, 'misses' => 0, 'writes' => 0, 'deletes' => 0],
        [...$aggregates['duplicateQuery'], 'count' => 1, 'totalRepeats' => 6, 'maxRepeats' => 6],
        [...$aggregates['server'], 'loadAverage1' => 1.25, 'loadAverage5' => null, 'cpuLimit' => null, 'memoryUsedMb' => null, 'isContainer' => false],
        array_diff_key([...$aggregates['queueWait'], 'count' => 1, 'totalMs' => 10, 'maxMs' => 10], ['histogram' => true]),
        array_diff_key($aggregates['logLevel'], ['channel' => true]),
    ];

    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope(array_values($aggregates)), $headers)->assertCreated();
    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope($second), $headers)->assertCreated();

    $row = fn (string $type): RuntimeAggregate => $project->runtimeAggregates()->where('type', $type)->sole();

    expect([$row('request')->count, $row('request')->error_count, $row('request')->total_ms, $row('request')->max_ms])->toBe([6, 1, 500.5, 900.0])
        ->and($row('request')->histogram)->toBe([1, 0, 1, 0, 1, 1, 1, 0, 0, 0, 0, 1])
        ->and($row('requestMemory')->metrics)->toEqual(['totalMb' => 130.5, 'maxMb' => 90.5])
        ->and($row('cache')->metrics)->toEqual(['hits' => 5, 'misses' => 1, 'writes' => 1, 'deletes' => 0])
        ->and($row('cache')->count)->toBe(7)
        ->and($row('duplicateQuery')->metrics)->toEqual(['totalRepeats' => 46, 'maxRepeats' => 15])
        ->and($row('server')->metrics)->toEqual([
            'loadAverage1' => 1.25, 'loadAverage5' => 0.4, 'loadAverage15' => 0.3, 'cpuCores' => 8, 'cpuLimit' => 1.5,
            'memoryUsedMb' => 900, 'memoryTotalMb' => 2048, 'diskUsedGb' => 20.5, 'diskTotalGb' => 80, 'isContainer' => true,
        ])
        ->and($row('server')->count)->toBe(2)
        ->and($row('queueWait')->histogram)->toBeNull()
        ->and($row('queueWait')->total_ms)->toBe(410.5);

    // A log entry without channel is its own series, never merged with one that has it.
    expect($project->runtimeAggregates()->where('type', 'logLevel')->count())->toBe(2);
});

it('keeps gauges null when every entry is null and reads a missing isContainer as false', function () {
    [$project, $headers] = contractProject();
    $server = array_diff_key([...coreRuntimeAggregates()['server'], 'cpuCores' => null, 'memoryUsedMb' => null, 'memoryTotalMb' => null], ['cpuLimit' => true, 'isContainer' => true]);

    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([$server]), $headers)->assertCreated();
    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([$server]), $headers)->assertCreated();

    expect($project->runtimeAggregates()->sole()->metrics)->toMatchArray(['cpuCores' => null, 'cpuLimit' => null, 'memoryUsedMb' => null, 'isContainer' => false, 'loadAverage1' => 0.52]);
});

it('keeps releases apart and stores entries without a release as before', function () {
    [$project, $headers] = contractProject();
    $request = coreRuntimeAggregates()['request'];

    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([$request], 'v1'), $headers)->assertCreated();
    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([$request], 'v2'), $headers)->assertCreated();
    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([$request], null), $headers)->assertCreated();
    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([$request], null), $headers)->assertCreated();

    expect($project->runtimeAggregates()->orderBy('id')->get()->map(fn (RuntimeAggregate $row): array => [$row->release, $row->count])->all())
        ->toBe([['v1', 4], ['v2', 4], [null, 8]]);
});

it('skips and counts entries of an unknown type and stores the rest of the batch', function () {
    [$project, $headers] = contractProject();
    $unknown = ['type' => 'gpu', 'bucket' => '2026-11-06T11:58:00Z', 'sampleRate' => 1, 'device' => 'cuda0', 'count' => 1, 'errorCount' => 0, 'utilisation' => 0.9];
    $envelope = coreRuntimeEnvelope([coreRuntimeAggregates()['request'], $unknown, [...$unknown, 'type' => 'futureType', 'whatever' => ['nested' => true]]]);

    $this->postJson(route('api.runtime.store'), $envelope, $headers)->assertCreated()->assertJson(['status' => 'stored']);
    $this->postJson(route('api.runtime.store'), $envelope, $headers)->assertOk()->assertJson(['status' => 'duplicate']);

    expect($project->runtimeAggregates()->pluck('type')->map->value->all())->toBe(['request'])
        ->and($project->fresh()?->skipped_runtime_aggregates)->toBe(2);
});

it('still rejects a known type that breaks its own contract', function (string $type, string $field, mixed $value) {
    [$project, $headers] = contractProject();

    $this->postJson(route('api.runtime.store'), coreRuntimeEnvelope([[...coreRuntimeAggregates()[$type], $field => $value]]), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors("runtime.aggregates.0.{$field}");

    expect(RuntimeAggregate::query()->count())->toBe(0);
})->with([
    'cache without hits' => ['cache', 'hits', null],
    'memory below zero' => ['requestMemory', 'maxMb', -1],
    'repeats below the threshold' => ['duplicateQuery', 'maxRepeats', 1],
    'unknown log level' => ['logLevel', 'level', 'fatal'],
    'unknown exit code class' => ['command', 'exitCodeClass', '2'],
    'short histogram' => ['queueWait', 'histogram', [1, 2, 1]],
    'server gauge as text' => ['server', 'loadAverage1', 'high'],
    'container flag as text' => ['server', 'isContainer', 'maybe'],
    'unknown AI operation' => ['ai', 'operation', 'chat'],
    'AI tokens as text' => ['ai', 'inputTokens', 'many'],
    'AI without failovers' => ['ai', 'failoverCount', null],
    'AI tool without a tool' => ['aiTool', 'tool', null],
]);
