<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Frozen ingest API (#50, `docs/API.md`): the status code and the JSON body of every documented
 * answer of `POST /api/reports`, `/api/runtime` and `/api/exceptions` must match
 * `tests/Fixtures/ingest-api-contract.json`. A failure here is a change to a public contract that
 * excubia-core 1.x relies on: within 1.x only additive changes are allowed, and they must be
 * announced in `docs/API.md` and the CHANGELOG before the snapshot is updated.
 *
 * Values that change per request (`reportId`) are replaced by their JSON type in angle brackets.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    config(['excubia.push_max_kilobytes' => 2, 'excubia.push_requests_per_minute' => 30]);
});

/**
 * @return array<string, mixed>
 */
function ingestContractSnapshot(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/ingest-api-contract.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The smallest valid envelope of each endpoint, with a fixed batch id.
 *
 * @return array<string, mixed>
 */
function ingestContractEnvelope(string $endpoint, string $batchId = '6f1c2b8e-4d3a-4f5b-9c7d-1e2f3a4b5c6d'): array
{
    $envelope = ['protocolVersion' => 1, 'batchId' => $batchId, 'sentAt' => '2026-11-07T08:00:00Z'];

    return $envelope + match ($endpoint) {
        'reports' => ['report' => ['schemaVersion' => 1, 'checks' => [], 'upgradePlan' => []]],
        'runtime' => ['type' => 'runtime', 'runtime' => ['bucketSeconds' => 60, 'aggregates' => []]],
        'exceptions' => ['type' => 'exceptions', 'exceptions' => ['groups' => []]],
    };
}

/**
 * Sends a raw body, so invalid JSON can be sent too.
 */
function ingestContractPost(string $endpoint, ?string $token, string $body): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }

    return test()->call('POST', '/api/'.$endpoint, [], [], [], $server, $body);
}

/**
 * The status, the contract headers and the body, with per-request values replaced by their type.
 *
 * @return array{status: int, body: mixed, headers?: list<string>}
 */
function ingestContractAnswer(TestResponse $response): array
{
    $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    if (is_array($body) && array_key_exists('reportId', $body)) {
        $body['reportId'] = '<'.get_debug_type($body['reportId']).'>';
    }

    $answer = ['status' => $response->getStatusCode(), 'body' => $body];
    $headers = array_values(array_filter(['Retry-After'], fn (string $header): bool => $response->headers->has($header)));

    return $headers === [] ? $answer : $answer + ['headers' => $headers];
}

/**
 * Runs every documented case of one endpoint and returns its answers by case name.
 *
 * @return array<string, array<string, mixed>>
 */
function ingestContractAnswers(string $endpoint): array
{
    $project = Project::factory()->create();
    $token = app(RotateProjectTokenAction::class)->execute($project);
    $disabled = Project::factory()->create(['is_enabled' => false]);
    $disabledToken = app(RotateProjectTokenAction::class)->execute($disabled);
    $envelope = ingestContractEnvelope($endpoint);
    $json = json_encode($envelope, JSON_THROW_ON_ERROR);
    $answers = [];

    $answers['stored'] = ingestContractAnswer(ingestContractPost($endpoint, $token, $json));
    $answers['duplicate'] = ingestContractAnswer(ingestContractPost($endpoint, $token, $json));
    $answers['missing_token'] = ingestContractAnswer(ingestContractPost($endpoint, null, $json));
    $answers['unknown_token'] = ingestContractAnswer(ingestContractPost($endpoint, 'not-a-token', $json));
    $answers['disabled_project'] = ingestContractAnswer(ingestContractPost($endpoint, $disabledToken, $json));
    $answers['too_large'] = ingestContractAnswer(ingestContractPost($endpoint, $token, json_encode($envelope + ['padding' => str_repeat('a', 2100)], JSON_THROW_ON_ERROR)));
    $answers['protocol_version_2'] = ingestContractAnswer(ingestContractPost($endpoint, $token, json_encode(['protocolVersion' => 2] + ingestContractEnvelope($endpoint, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), JSON_THROW_ON_ERROR)));
    $answers['invalid_json'] = ingestContractAnswer(ingestContractPost($endpoint, $token, '{"protocolVersion": 1,'));
    $answers['json_too_deep'] = ingestContractAnswer(ingestContractPost($endpoint, $token, str_repeat('[', 600).str_repeat(']', 600)));

    if ($endpoint === 'runtime') {
        $unknownType = ingestContractEnvelope($endpoint, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $unknownType['runtime']['aggregates'] = [['type' => 'typeFromANewerCore', 'bucket' => '2026-11-07T07:59:00Z', 'count' => 1]];
        $answers['unknown_runtime_type'] = ingestContractAnswer(ingestContractPost($endpoint, $token, json_encode($unknownType, JSON_THROW_ON_ERROR)));
    }

    $answers['wrong_method'] = ingestContractAnswer(test()->getJson('/api/'.$endpoint, ['Authorization' => 'Bearer '.$token]));

    config(['excubia.push_requests_per_minute' => 1]);
    ingestContractPost($endpoint, 'throttle-'.$endpoint, $json);
    $answers['throttled'] = ingestContractAnswer(ingestContractPost($endpoint, 'throttle-'.$endpoint, $json));

    return $answers;
}

it('answers every documented case of the endpoint as frozen in the snapshot', function (string $endpoint) {
    expect(ingestContractAnswers($endpoint))->toBe(ingestContractSnapshot()['endpoints'][$endpoint]);
})->with(['reports', 'runtime', 'exceptions']);

it('freezes exactly the routes of routes/api.php, each documented in docs/API.md', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
        ->map(fn ($route): string => implode('|', $route->methods()).' /'.$route->uri())
        ->sort()
        ->values()
        ->all();
    $documentation = (string) file_get_contents(base_path('docs/API.md'));

    expect($routes)->toBe(['POST /api/exceptions', 'POST /api/reports', 'POST /api/runtime'])
        ->and(array_keys(ingestContractSnapshot()['endpoints']))->toBe(['reports', 'runtime', 'exceptions']);

    foreach (['reports', 'runtime', 'exceptions'] as $endpoint) {
        expect($documentation)->toContain('`POST /api/'.$endpoint.'`');
    }
});
