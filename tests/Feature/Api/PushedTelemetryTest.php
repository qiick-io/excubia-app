<?php

declare(strict_types=1);

use App\Actions\RevokeProjectTokenAction;
use App\Actions\RotateProjectTokenAction;
use App\Enums\ExceptionStatusEnum;
use App\Enums\RuntimeTypeEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `POST /api/runtime` (#48) and `POST /api/exceptions` (#49): runtime aggregates and masked
 * exception groups pushed by `excubia:push-runtime`, authenticated by the project token, stored
 * once per batchId and additive across batches.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-05 12:00:00');
});

/**
 * @return array{0: Project, 1: string}
 */
function telemetryProject(): array
{
    $project = Project::factory()->create();

    return [$project, app(RotateProjectTokenAction::class)->execute($project)];
}

/**
 * @param  list<array<string, mixed>>  $aggregates
 * @return array<string, mixed>
 */
function runtimeEnvelope(array $aggregates = []): array
{
    return [
        'protocolVersion' => 1,
        'type' => 'runtime',
        'batchId' => (string) Str::uuid(),
        'sentAt' => '2026-11-05T11:59:00Z',
        'runtime' => ['bucketSeconds' => 60, 'aggregates' => $aggregates ?: [
            ['type' => 'request', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'route' => 'projects.show', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 10, 'errorCount' => 0, 'totalMs' => 1200.5, 'maxMs' => 340.2],
            ['type' => 'request', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'route' => 'projects.show', 'method' => 'GET', 'statusClass' => '5xx', 'count' => 1, 'errorCount' => 1, 'totalMs' => 90, 'maxMs' => 90],
            ['type' => 'slowQuery', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'sql' => 'select * from users where id = ?', 'connection' => 'pgsql', 'count' => 2, 'errorCount' => 0, 'totalMs' => 2400, 'maxMs' => 1500],
            ['type' => 'job', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 0.5, 'job' => 'App\\Jobs\\SendReport', 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'failed', 'count' => 1, 'errorCount' => 1, 'totalMs' => 30, 'maxMs' => 30],
            ['type' => 'scheduledTask', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'task' => 'excubia:push', 'outcome' => 'finished', 'count' => 1, 'errorCount' => 0, 'totalMs' => 800, 'maxMs' => 800],
            ['type' => 'outgoingHttp', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'host' => 'api.github.com', 'statusClass' => '2xx', 'count' => 3, 'errorCount' => 0, 'totalMs' => 600, 'maxMs' => 250],
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function exceptionGroupPayload(array $overrides = []): array
{
    return array_replace([
        'class' => 'Illuminate\\Database\\QueryException',
        'fingerprint' => str_repeat('a', 64),
        'message' => 'SQLSTATE[23505]: duplicate key value for [email]',
        'frames' => [['file' => 'app/Actions/CreateUserAction.php', 'line' => 42, 'function' => 'App\\Actions\\CreateUserAction::execute', 'inApp' => true]],
        'previous' => ['PDOException'],
        'context' => ['kind' => 'request', 'name' => 'users.store'],
        'environment' => 'production',
        'count' => 3,
        'firstSeen' => '2026-11-05T11:00:00Z',
        'lastSeen' => '2026-11-05T11:30:00Z',
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $groups
 * @return array<string, mixed>
 */
function exceptionsEnvelope(array $groups): array
{
    return ['protocolVersion' => 1, 'type' => 'exceptions', 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-05T11:59:00Z', 'exceptions' => ['groups' => $groups]];
}

it('stores a runtime batch per type and series', function () {
    [$project, $token] = telemetryProject();

    $this->postJson(route('api.runtime.store'), runtimeEnvelope(), ['Authorization' => "Bearer {$token}"])
        ->assertCreated()
        ->assertJson(['status' => 'stored']);

    expect($project->runtimeAggregates()->count())->toBe(6);

    $failedJob = $project->runtimeAggregates()->where('type', RuntimeTypeEnum::Job->value)->sole();
    expect($failedJob->sample_rate)->toBe(0.5)
        ->and($failedJob->dimensions)->toBe(['job' => 'App\\Jobs\\SendReport', 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'failed'])
        ->and($failedJob->bucket->toIso8601ZuluString())->toBe('2026-11-05T11:58:00Z');
});

it('adds aggregates of the same series across batches and keeps a repeated batchId once', function () {
    [$project, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];
    $first = runtimeEnvelope([['type' => 'request', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'route' => 'home', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 4, 'errorCount' => 0, 'totalMs' => 100, 'maxMs' => 40]]);
    $second = runtimeEnvelope([['type' => 'request', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'route' => 'home', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 6, 'errorCount' => 0, 'totalMs' => 300, 'maxMs' => 90]]);

    $this->postJson(route('api.runtime.store'), $first, $headers)->assertCreated();
    $this->postJson(route('api.runtime.store'), $first, $headers)->assertOk()->assertJson(['status' => 'duplicate']);
    $this->postJson(route('api.runtime.store'), $second, $headers)->assertCreated();

    $series = RuntimeAggregate::query()->sole();
    expect([$series->count, $series->total_ms, $series->max_ms])->toBe([10, 400.0, 90.0]);
});

it('rejects runtime pushes with a wrong token, an unknown protocol or invalid aggregates', function () {
    [$project, $token] = telemetryProject();

    $this->postJson(route('api.runtime.store'), runtimeEnvelope())->assertUnauthorized();
    $this->postJson(route('api.runtime.store'), runtimeEnvelope(), ['Authorization' => 'Bearer excubia_wrong'])->assertUnauthorized();

    $this->postJson(route('api.runtime.store'), [...runtimeEnvelope(), 'protocolVersion' => 2], ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('protocolVersion');

    $this->postJson(route('api.runtime.store'), runtimeEnvelope([['type' => 'request', 'bucket' => '2026-11-05T11:58:30Z', 'sampleRate' => 1, 'route' => 'home', 'method' => 'GET', 'statusClass' => '6xx', 'count' => 1, 'errorCount' => 0, 'totalMs' => 1, 'maxMs' => 1]]), ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['runtime.aggregates.0.bucket', 'runtime.aggregates.0.statusClass']);

    $this->postJson(route('api.runtime.store'), runtimeEnvelope([['type' => 'job', 'bucket' => '2026-11-05T11:58:00Z', 'sampleRate' => 1, 'job' => 'X', 'count' => 1, 'errorCount' => 0, 'totalMs' => 1, 'maxMs' => 1]]), ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['runtime.aggregates.0.queue', 'runtime.aggregates.0.outcome']);

    app(RevokeProjectTokenAction::class)->execute($project);
    $this->postJson(route('api.runtime.store'), runtimeEnvelope(), ['Authorization' => "Bearer {$token}"])->assertUnauthorized();

    expect(RuntimeAggregate::query()->count())->toBe(0);
});

it('stores exception groups by fingerprint and environment, additive across batches', function () {
    [$project, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([exceptionGroupPayload(), exceptionGroupPayload(['environment' => 'staging', 'count' => 1])]), $headers)
        ->assertCreated();

    $later = exceptionsEnvelope([exceptionGroupPayload(['count' => 2, 'firstSeen' => '2026-11-05T10:00:00Z', 'lastSeen' => '2026-11-05T11:50:00Z', 'context' => ['kind' => 'job', 'name' => 'App\\Jobs\\Import'], 'message' => 'a newer message'])]);
    $this->postJson(route('api.exceptions.store'), $later, $headers)->assertCreated();
    $this->postJson(route('api.exceptions.store'), $later, $headers)->assertOk()->assertJson(['status' => 'duplicate']);

    expect($project->exceptionGroups()->count())->toBe(2);

    $production = $project->exceptionGroups()->where('environment', 'production')->sole();
    expect($production->count)->toBe(5)
        ->and($production->first_seen->toIso8601ZuluString())->toBe('2026-11-05T10:00:00Z')
        ->and($production->last_seen->toIso8601ZuluString())->toBe('2026-11-05T11:50:00Z')
        ->and($production->context_kind)->toBe('job')
        ->and($production->message)->toBe('SQLSTATE[23505]: duplicate key value for [email]')
        ->and($production->frames[0]['file'])->toBe('app/Actions/CreateUserAction.php')
        ->and($production->status)->toBe(ExceptionStatusEnum::Open);
});

it('reopens a resolved exception group and keeps an ignored one ignored', function () {
    [$project, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];
    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([exceptionGroupPayload(), exceptionGroupPayload(['fingerprint' => str_repeat('b', 64)])]), $headers)->assertCreated();

    ExceptionGroup::query()->where('fingerprint', str_repeat('a', 64))->update(['status' => 'resolved', 'resolved_at' => Date::now()]);
    ExceptionGroup::query()->where('fingerprint', str_repeat('b', 64))->update(['status' => 'ignored']);

    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([exceptionGroupPayload(), exceptionGroupPayload(['fingerprint' => str_repeat('b', 64)])]), $headers)->assertCreated();

    expect(ExceptionGroup::query()->where('fingerprint', str_repeat('a', 64))->sole())
        ->status->toBe(ExceptionStatusEnum::Open)
        ->resolved_at->toBeNull()
        ->and(ExceptionGroup::query()->where('fingerprint', str_repeat('b', 64))->sole()->status)->toBe(ExceptionStatusEnum::Ignored);
});

it('rejects invalid or oversized exception pushes', function () {
    [, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([exceptionGroupPayload(['fingerprint' => 'short', 'context' => ['kind' => 'cli', 'name' => 'x']])]), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exceptions.groups.0.fingerprint', 'exceptions.groups.0.context.kind']);

    $this->postJson(route('api.exceptions.store'), [...exceptionsEnvelope([exceptionGroupPayload()]), 'type' => 'runtime'], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    config(['excubia.push_max_kilobytes' => 1]);
    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([exceptionGroupPayload(['message' => str_repeat('x', 1000)]), exceptionGroupPayload(['message' => str_repeat('y', 1000), 'fingerprint' => str_repeat('c', 64)])]), $headers)
        ->assertStatus(413);

    expect(ExceptionGroup::query()->count())->toBe(0);
});

it('prunes runtime aggregates and exception groups past their retention', function () {
    [$project, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];
    config(['excubia.runtime_retention_days' => 14, 'excubia.exceptions_retention_days' => 90]);

    $this->postJson(route('api.runtime.store'), runtimeEnvelope([
        ['type' => 'request', 'bucket' => '2026-10-01T10:00:00Z', 'sampleRate' => 1, 'route' => 'old', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 1, 'errorCount' => 0, 'totalMs' => 1, 'maxMs' => 1],
        ['type' => 'request', 'bucket' => '2026-11-04T10:00:00Z', 'sampleRate' => 1, 'route' => 'new', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 1, 'errorCount' => 0, 'totalMs' => 1, 'maxMs' => 1],
    ]), $headers)->assertCreated();
    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([
        exceptionGroupPayload(['firstSeen' => '2026-06-01T10:00:00Z', 'lastSeen' => '2026-07-01T10:00:00Z']),
        exceptionGroupPayload(['fingerprint' => str_repeat('d', 64)]),
    ]), $headers)->assertCreated();

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect(RuntimeAggregate::query()->pluck('dimensions')->pluck('route')->all())->toBe(['new'])
        ->and(ExceptionGroup::query()->pluck('fingerprint')->all())->toBe([str_repeat('d', 64)]);
});

it('prunes with the Data retention settings over the config defaults', function () {
    [$project, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];
    app(SettingsRepositoryService::class)->setMany(['runtime_retention_days' => 1, 'exceptions_retention_days' => 1]);

    $this->postJson(route('api.runtime.store'), runtimeEnvelope([
        ['type' => 'request', 'bucket' => '2026-11-04T10:00:00Z', 'sampleRate' => 1, 'route' => 'yesterday', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 1, 'errorCount' => 0, 'totalMs' => 1, 'maxMs' => 1],
    ]), $headers)->assertCreated();
    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([
        exceptionGroupPayload(['firstSeen' => '2026-11-01T10:00:00Z', 'lastSeen' => '2026-11-02T10:00:00Z']),
    ]), $headers)->assertCreated();

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect(RuntimeAggregate::query()->count())->toBe(0)
        ->and(ExceptionGroup::query()->count())->toBe(0);
});

it('keeps the first and last release of an exception group and flags a regression (#106)', function () {
    [$project, $token] = telemetryProject();
    $headers = ['Authorization' => "Bearer {$token}"];
    $push = fn (array $group, ?string $release) => test()->postJson(
        route('api.exceptions.store'),
        array_replace_recursive(exceptionsEnvelope([$group]), $release === null ? [] : ['exceptions' => ['release' => $release]]),
        $headers,
    )->assertCreated();
    $group = fn (): ExceptionGroup => $project->exceptionGroups()->sole();

    $push(exceptionGroupPayload(), 'v1.0.0');
    $push(exceptionGroupPayload(['lastSeen' => '2026-11-05T11:40:00Z']), 'v1.1.0');
    expect($group())->first_release->toBe('v1.0.0')->last_release->toBe('v1.1.0')->regressed_release->toBeNull();

    // Back in the release it was last seen in: the fix may not be deployed yet, only a reopen.
    $group()->update(['status' => 'resolved', 'resolved_at' => Date::now()]);
    $push(exceptionGroupPayload(['lastSeen' => '2026-11-05T11:45:00Z']), 'v1.1.0');
    expect($group())->status->toBe(ExceptionStatusEnum::Open)->regressed_release->toBeNull();

    // Back in a later release: a regression in that release.
    $group()->update(['status' => 'resolved', 'resolved_at' => Date::now()]);
    $push(exceptionGroupPayload(['lastSeen' => '2026-11-05T11:50:00Z']), 'v1.2.0');
    expect($group())->status->toBe(ExceptionStatusEnum::Open)->last_release->toBe('v1.2.0')->regressed_release->toBe('v1.2.0');

    // A batch without a release (older core) keeps what is known.
    $push(exceptionGroupPayload(['lastSeen' => '2026-11-05T11:55:00Z']), null);
    expect($group())->first_release->toBe('v1.0.0')->last_release->toBe('v1.2.0')->count->toBe(15);
});

it('stores exception groups without a release as before (#106)', function () {
    [$project, $token] = telemetryProject();

    $this->postJson(route('api.exceptions.store'), exceptionsEnvelope([exceptionGroupPayload()]), ['Authorization' => "Bearer {$token}"])->assertCreated();
    $this->postJson(route('api.exceptions.store'), [...exceptionsEnvelope([exceptionGroupPayload()]), 'exceptions' => ['groups' => [exceptionGroupPayload()], 'release' => str_repeat('a', 101)]], ['Authorization' => "Bearer {$token}"])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exceptions.release');

    expect($project->exceptionGroups()->sole())->first_release->toBeNull()->last_release->toBeNull()->regressed_release->toBeNull();
});
