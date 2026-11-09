<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\RuntimeTypeEnum;
use App\Http\Requests\StorePushedRuntimeRequest;
use App\Models\Project;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Forward compatibility of the ingest API (#235, `docs/API.md` "Unknown fields and types"): an
 * Excubia App 1.0 must keep accepting what a later excubia-core 1.x pushes. Core plans new runtime
 * types with shapes the app has never seen (core #243 `trace` with nested span objects, #244
 * `logEntry` with masked messages and context keys, #245 `users` with a sketch string) and may add
 * fields to every envelope. Those entries must be skipped and counted, never rejected, never a 500,
 * and never walked by the validator: wildcard rules flatten the whole subtree they cover, so a
 * large unknown entry under one would make a push slow (#195).
 *
 * The timing budget (1 s for a 1 MB body) is ten times what the push takes locally, so it only
 * fails when the validator starts walking the unknown data again, not on a slow CI runner.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Exceptions::fake();
    Date::setTestNow('2026-11-06 12:00:00');
    config(['app.debug' => false]);
});

const FORWARD_COMPAT_BUDGET_SECONDS = 1.0;

/**
 * @return array{0: Project, 1: string}
 */
function forwardCompatProject(): array
{
    $project = Project::factory()->create();

    return [$project, app(RotateProjectTokenAction::class)->execute($project)];
}

/**
 * Sends the body as core does: raw JSON with the project token.
 */
function forwardCompatPost(string $endpoint, string $token, string $body): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];

    return test()->call('POST', '/api/'.$endpoint, [], [], [], $server, $body);
}

/**
 * Two entries of known types, each with fields a later core may add.
 *
 * @return list<array<string, mixed>>
 */
function forwardCompatKnownAggregates(): array
{
    $base = ['bucket' => '2026-11-06T11:58:00Z', 'sampleRate' => 1, 'count' => 4, 'errorCount' => 1, 'totalMs' => 400.5, 'maxMs' => 250.25];

    return [
        ['type' => 'request', ...$base, 'route' => 'projects.show', 'method' => 'GET', 'statusClass' => '5xx', 'traceIds' => ['a1b2', 'c3d4'], 'p99Ms' => null, 'isSampled' => true],
        ['type' => 'job', ...$base, 'job' => 'App\\Jobs\\SendReport', 'queue' => '', 'connection' => 'redis', 'outcome' => 'failed', 'attempts' => ['first' => 1, 'last' => 3.0], 'tags' => []],
    ];
}

/**
 * One entry of each type core plans for 1.1, in shapes the app has never seen: nested lists of
 * objects, long strings, floats, integers, nulls, booleans and empty arrays.
 *
 * @return array<string, array<string, mixed>>
 */
function forwardCompatFutureAggregates(int $spanCount = 150): array
{
    $base = ['bucket' => '2026-11-06T11:58:00Z', 'sampleRate' => 0.25, 'count' => 3, 'errorCount' => 0];
    $spans = [];

    for ($i = 0; $i < $spanCount; $i++) {
        $spans[] = [
            'spanId' => sprintf('%016x', $i),
            'parentSpanId' => $i === 0 ? null : sprintf('%016x', intdiv($i - 1, 4)),
            'name' => 'query '.$i,
            'startMs' => $i * 1.5,
            'durationMs' => $i % 7,
            'isError' => $i % 11 === 0,
            'attributes' => ['db.system' => 'pgsql', 'rows' => $i, 'cached' => false, 'ratio' => 0.5, 'none' => null],
            'events' => [],
            'links' => [['traceId' => str_repeat('f', 32), 'attributes' => []]],
        ];
    }

    return [
        'trace' => ['type' => 'trace', ...$base, 'traceId' => str_repeat('e', 32), 'root' => 'GET /projects/{project}', 'totalMs' => 812.75, 'spans' => $spans, 'droppedSpans' => 0],
        'logEntry' => [
            'type' => 'logEntry', ...$base, 'level' => 'warning', 'channel' => 'stack',
            'message' => str_repeat('Masked message with [redacted] placeholders. ', 230),
            'contextKeys' => ['userId', 'orderId', 'retry'], 'extraKeys' => [], 'isTruncated' => true, 'lengthBytes' => 10240,
        ],
        'users' => ['type' => 'users', ...$base, 'sketch' => base64_encode(str_repeat("\x01\x7f\x00\xff", 2560)), 'precision' => 14, 'estimate' => 1234.5, 'isAuthenticatedOnly' => false, 'guards' => ['web' => 3, 'api' => null]],
    ];
}

/**
 * A runtime envelope with fields a later core may add on the batch and on `runtime`.
 *
 * @param  list<array<string, mixed>>  $aggregates
 * @return array<string, mixed>
 */
function forwardCompatRuntimeEnvelope(array $aggregates): array
{
    return [
        'protocolVersion' => 1,
        'type' => 'runtime',
        'batchId' => (string) Str::uuid(),
        'sentAt' => '2026-11-06T11:59:00Z',
        'coreVersion' => '1.1.0',
        'capabilities' => ['traces' => true, 'compression' => null, 'maxSpans' => 500, 'ratio' => 0.1, 'types' => []],
        'runtime' => ['bucketSeconds' => 60, 'release' => 'v1.4.0', 'droppedAggregates' => 0, 'sampling' => ['trace' => 0.25], 'aggregates' => $aggregates],
    ];
}

/**
 * Copies of the future entries until their JSON is just over `$kilobytes` KB.
 *
 * @return list<array<string, mixed>>
 */
function forwardCompatUnknownAggregatesOf(int $kilobytes): array
{
    $futureAggregates = array_values(forwardCompatFutureAggregates());
    $copies = intdiv($kilobytes * 1024, strlen(json_encode($futureAggregates, JSON_THROW_ON_ERROR))) + 1;

    return array_merge(...array_fill(0, $copies, $futureAggregates));
}

/**
 * Fills the body to exactly `$bytes` bytes with an unknown top-level `padding` string.
 *
 * @param  array<string, mixed>  $envelope
 */
function forwardCompatBodyOf(array $envelope, int $bytes): string
{
    // `,"padding":""` adds 13 bytes before the padding itself.
    $padding = $bytes - strlen(json_encode($envelope, JSON_THROW_ON_ERROR)) - 13;
    $body = json_encode([...$envelope, 'padding' => str_repeat('x', max(0, $padding))], JSON_THROW_ON_ERROR);

    expect(strlen($body))->toBe($bytes);

    return $body;
}

/**
 * Runs the push and returns the answer with the seconds it took.
 *
 * @return array{0: TestResponse, 1: float}
 */
function forwardCompatTimedPost(string $endpoint, string $token, string $body): array
{
    $startedAt = hrtime(true);
    $response = forwardCompatPost($endpoint, $token, $body);

    return [$response, (hrtime(true) - $startedAt) / 1e9];
}

it('stores the known entries of a batch and skips and counts the entries of future types', function () {
    [$project, $token] = forwardCompatProject();
    $future = forwardCompatFutureAggregates();
    [$request, $job] = forwardCompatKnownAggregates();
    $body = json_encode(forwardCompatRuntimeEnvelope([$future['trace'], $request, $future['logEntry'], $job, $future['users']]), JSON_THROW_ON_ERROR);

    $capabilities = ['privacyLevel' => 'aggregate', 'types' => RuntimeTypeEnum::values(), 'skipped' => 3];

    // The capabilities (#239) tell a newer core which types it may stop sending; a duplicate answers the same.
    forwardCompatPost('runtime', $token, $body)->assertCreated()->assertExactJson(['status' => 'stored', 'capabilities' => $capabilities]);
    forwardCompatPost('runtime', $token, $body)->assertOk()->assertExactJson(['status' => 'duplicate', 'capabilities' => $capabilities]);

    expect($project->runtimeAggregates()->orderBy('id')->get()->map(fn ($row): array => [$row->type->value, $row->count])->all())
        ->toBe([['request', 4], ['job', 4]])
        ->and($project->fresh()?->skipped_runtime_aggregates)->toBe(3);

    Exceptions::assertNothingReported();
});

it('accepts a batch made only of one future type', function (string $type) {
    [$project, $token] = forwardCompatProject();
    $body = json_encode(forwardCompatRuntimeEnvelope([forwardCompatFutureAggregates()[$type]]), JSON_THROW_ON_ERROR);

    forwardCompatPost('runtime', $token, $body)->assertCreated();

    expect($project->runtimeAggregates()->count())->toBe(0)
        ->and($project->fresh()?->skipped_runtime_aggregates)->toBe(1);

    Exceptions::assertNothingReported();
})->with(['trace', 'logEntry', 'users']);

it('accepts a batch of future types up to the body limit and answers 413 above it', function () {
    config(['excubia.push_max_kilobytes' => 1024]);
    [$project, $token] = forwardCompatProject();
    $envelope = forwardCompatRuntimeEnvelope([...forwardCompatKnownAggregates(), ...forwardCompatUnknownAggregatesOf(960)]);
    $unknownCount = count($envelope['runtime']['aggregates']) - 2;

    forwardCompatPost('runtime', $token, forwardCompatBodyOf($envelope, 1024 * 1024))->assertCreated();

    $envelope['batchId'] = (string) Str::uuid();

    forwardCompatPost('runtime', $token, forwardCompatBodyOf($envelope, 1024 * 1024 + 1))
        ->assertStatus(413)
        ->assertExactJson(['message' => 'The body is larger than 1024 KB.']);

    expect($project->runtimeAggregates()->count())->toBe(2)
        ->and($project->fresh()?->skipped_runtime_aggregates)->toBe($unknownCount);

    Exceptions::assertNothingReported();
});

it('validates 1 MB of future runtime entries without walking them', function () {
    config(['excubia.push_max_kilobytes' => 1100]);
    [$project, $token] = forwardCompatProject();
    $body = json_encode(forwardCompatRuntimeEnvelope([...forwardCompatKnownAggregates(), ...forwardCompatUnknownAggregatesOf(1024)]), JSON_THROW_ON_ERROR);

    [$response, $seconds] = forwardCompatTimedPost('runtime', $token, $body);

    $response->assertCreated();

    // No wildcard rule (it would flatten every entry) and no rule for an unknown entry's index.
    $ruleKeys = array_keys(StorePushedRuntimeRequest::create('/api/runtime', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body)->rules());

    expect(strlen($body))->toBeGreaterThan(1000 * 1024)
        ->and($seconds)->toBeLessThan(FORWARD_COMPAT_BUDGET_SECONDS)
        ->and(array_filter($ruleKeys, fn (string $key): bool => str_contains($key, '*') || preg_match('/^runtime\.aggregates\.(?![01]\.)/', $key) === 1))->toBe([])
        ->and($project->runtimeAggregates()->count())->toBe(2);

    Exceptions::assertNothingReported();
});

it('accepts 1 MB of future fields on a report without walking them', function () {
    config(['excubia.push_max_kilobytes' => 1100]);
    [$project, $token] = forwardCompatProject();
    $future = forwardCompatUnknownAggregatesOf(1024);
    $check = ['checkId' => 'composer-audit', 'name' => 'Composer audit', 'status' => 'pass', 'findings' => [], 'durationMs' => 12.5, 'isCached' => false, 'tags' => []];
    $envelope = [
        'protocolVersion' => 1, 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-06T11:59:00Z', 'coreVersion' => '1.1.0', 'capabilities' => ['traces' => true, 'none' => null],
        'report' => ['schemaVersion' => 1, 'checks' => [$check], 'upgradePlan' => [], 'traces' => $future, 'summary' => ['ratio' => 0.5, 'count' => 3, 'isPartial' => false, 'notes' => null]],
    ];
    $body = json_encode($envelope, JSON_THROW_ON_ERROR);

    [$response, $seconds] = forwardCompatTimedPost('reports', $token, $body);

    $response->assertCreated()->assertJson(['status' => 'stored']);

    expect(strlen($body))->toBeGreaterThan(1000 * 1024)
        ->and($seconds)->toBeLessThan(FORWARD_COMPAT_BUDGET_SECONDS)
        ->and($project->healthReports()->count())->toBe(1);

    Exceptions::assertNothingReported();
});

it('accepts 1 MB of future fields on an exceptions batch without walking them', function () {
    config(['excubia.push_max_kilobytes' => 1100]);
    [$project, $token] = forwardCompatProject();
    $group = [
        'class' => 'RuntimeException', 'fingerprint' => str_repeat('a', 64), 'message' => 'Masked [redacted]',
        'frames' => [['file' => 'app/Actions/CreateUserAction.php', 'line' => 42, 'function' => 'execute', 'inApp' => true, 'column' => null]],
        'previous' => [], 'context' => ['kind' => 'trace', 'name' => 'users.store', 'spanId' => 'abc'],
        'environment' => 'production', 'count' => 2, 'firstSeen' => '2026-11-06T11:00:00Z', 'lastSeen' => '2026-11-06T11:30:00Z',
        'breadcrumbs' => [['level' => 'info', 'atMs' => 1.5, 'isUser' => false, 'data' => []]], 'severityScore' => 0.75, 'handled' => null,
    ];
    $envelope = [
        'protocolVersion' => 1, 'type' => 'exceptions', 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-06T11:59:00Z', 'coreVersion' => '1.1.0',
        'exceptions' => ['groups' => [$group], 'traces' => forwardCompatUnknownAggregatesOf(1024), 'droppedGroups' => 0],
    ];
    $body = json_encode($envelope, JSON_THROW_ON_ERROR);

    [$response, $seconds] = forwardCompatTimedPost('exceptions', $token, $body);

    $response->assertCreated()->assertExactJson(['status' => 'stored', 'capabilities' => ['privacyLevel' => 'aggregate', 'types' => RuntimeTypeEnum::values(), 'skipped' => 0]]);

    expect(strlen($body))->toBeGreaterThan(1000 * 1024)
        ->and($seconds)->toBeLessThan(FORWARD_COMPAT_BUDGET_SECONDS)
        ->and($project->exceptionGroups()->count())->toBe(1);

    Exceptions::assertNothingReported();
});
