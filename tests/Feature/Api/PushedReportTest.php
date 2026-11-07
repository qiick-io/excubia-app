<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\SeverityEnum;
use App\Models\HealthReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * `POST /api/reports` (#21): reports pushed by `excubia push`, authenticated by the project
 * token, stored once per batchId.
 */
beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @return array{0: Project, 1: string}
 */
function pushingProject(array $attributes = []): array
{
    $project = Project::factory()->create($attributes);

    return [$project, app(RotateProjectTokenAction::class)->execute($project)];
}

/**
 * A protocol version 1 envelope with one failing high finding, one warning low finding and a
 * passing one.
 *
 * @return array<string, mixed>
 */
function pushEnvelope(array $overrides = []): array
{
    return array_replace([
        'protocolVersion' => 1,
        'batchId' => (string) Str::uuid(),
        'sentAt' => '2026-11-05T08:00:00Z',
        'report' => [
            'schemaVersion' => 1,
            'checks' => [[
                'checkId' => 'composer-audit',
                'name' => 'Composer audit',
                'status' => 'fail',
                'findings' => [
                    ['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => 'guzzlehttp/guzzle', 'message' => 'Known vulnerability', 'fixHint' => 'composer update guzzlehttp/guzzle', 'evidenceUrl' => 'https://example.test/advisory', 'advisoryId' => 'GHSA-1234'],
                    ['checkId' => 'composer-audit', 'severity' => 'low', 'status' => 'warn', 'subject' => 'monolog/monolog', 'message' => 'Abandoned', 'fixHint' => '', 'evidenceUrl' => null],
                    ['checkId' => 'composer-audit', 'severity' => 'critical', 'status' => 'pass', 'subject' => 'laravel/framework', 'message' => 'OK', 'fixHint' => '', 'evidenceUrl' => null],
                ],
            ]],
            'upgradePlan' => [['stepNumber' => 1, 'message' => 'composer update guzzlehttp/guzzle']],
        ],
    ], $overrides);
}

function pushReport(?string $token, array $envelope): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return test()->postJson(route('api.reports.store'), $envelope, $headers);
}

it('uses the generatedAt of an excubia-core 1.0 report and keeps its details', function () {
    [, $token] = pushingProject();
    $envelope = pushEnvelope();
    $envelope['report']['generatedAt'] = '2026-11-05T07:58:30Z';
    $envelope['report']['excubiaVersion'] = '1.0.0-beta.1';
    $envelope['report']['project'] = ['php' => ['version' => '8.4.12', 'cycle' => '8.4', 'securitySupportEndsOn' => '2028-12-31', 'activeSupportEndsOn' => '2026-12-31'], 'laravel' => null, 'node' => null];

    pushReport($token, $envelope)->assertCreated();

    $report = HealthReport::query()->sole();

    expect($report->generated_at->toIso8601ZuluString())->toBe('2026-11-05T07:58:30Z')
        ->and($report->report['project']['php']['cycle'])->toBe('8.4');

    pushReport($token, [...pushEnvelope(), 'report' => [...pushEnvelope()['report'], 'generatedAt' => 'yesterday-ish']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('report.generatedAt');
});

it('stores a valid push with its summary and updates last_report_at', function () {
    [$project, $token] = pushingProject();
    $envelope = pushEnvelope();

    $response = pushReport($token, $envelope)->assertCreated()->assertJson(['status' => 'stored']);

    $report = HealthReport::query()->sole();

    expect($response->json('reportId'))->toBe($report->id)
        ->and($report->project_id)->toBe($project->id)
        ->and($report->batch_id)->toBe($envelope['batchId'])
        ->and($report->generated_at->toIso8601ZuluString())->toBe('2026-11-05T08:00:00Z')
        ->and($report->worst_severity)->toBe(SeverityEnum::High)
        ->and([$report->critical_count, $report->high_count, $report->medium_count, $report->low_count, $report->info_count])->toBe([0, 1, 0, 1, 0])
        ->and($report->report)->toBe($envelope['report'])
        ->and($project->fresh()->last_report_at)->not->toBeNull();
});

it('stores a clean report without a worst severity', function () {
    [, $token] = pushingProject();
    $envelope = pushEnvelope();
    $envelope['report']['checks'][0]['findings'] = [];

    pushReport($token, $envelope)->assertCreated();

    expect(HealthReport::query()->sole()->worst_severity)->toBeNull();
});

it('rejects a missing, wrong or revoked token', function () {
    [$project, $token] = pushingProject();

    pushReport(null, pushEnvelope())->assertUnauthorized();
    pushReport('excubia_wrong', pushEnvelope())->assertUnauthorized();

    $project->forceFill(['push_token_hash' => null])->save();
    pushReport($token, pushEnvelope())->assertUnauthorized();

    expect(HealthReport::query()->count())->toBe(0);
});

it('does not accept a user session instead of the token', function () {
    $this->actingAs(User::factory()->create());

    pushReport(null, pushEnvelope())->assertUnauthorized();
});

it('rejects a disabled project', function () {
    [, $token] = pushingProject(['is_enabled' => false]);

    pushReport($token, pushEnvelope())->assertForbidden()->assertJson(['message' => 'This project is disabled in Excubia.']);

    expect(HealthReport::query()->count())->toBe(0);
});

it('stores a repeated batchId once', function () {
    [$project, $token] = pushingProject();
    $envelope = pushEnvelope();

    $first = pushReport($token, $envelope)->assertCreated();
    pushReport($token, $envelope)->assertOk()->assertJson(['status' => 'duplicate', 'reportId' => $first->json('reportId')]);

    expect($project->healthReports()->count())->toBe(1);
});

it('rejects an invalid payload', function (array $envelope, string $errorKey) {
    [, $token] = pushingProject();

    pushReport($token, $envelope)->assertUnprocessable()->assertJsonValidationErrors($errorKey);

    expect(HealthReport::query()->count())->toBe(0);
})->with([
    'missing batchId' => fn () => [array_diff_key(pushEnvelope(), ['batchId' => true]), 'batchId'],
    'batchId not a UUID v4' => fn () => [pushEnvelope(['batchId' => 'batch-1']), 'batchId'],
    'missing report' => fn () => [array_diff_key(pushEnvelope(), ['report' => true]), 'report'],
    'unknown severity' => function () {
        $envelope = pushEnvelope();
        $envelope['report']['checks'][0]['findings'][0]['severity'] = 'urgent';

        return [$envelope, 'report.checks.0.findings.0.severity'];
    },
    'missing upgrade plan' => function () {
        $envelope = pushEnvelope();
        unset($envelope['report']['upgradePlan']);

        return [$envelope, 'report.upgradePlan'];
    },
]);

it('rejects an unknown protocol version with a clear error', function () {
    [, $token] = pushingProject();

    pushReport($token, pushEnvelope(['protocolVersion' => 2]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.protocolVersion.0', 'Unsupported protocolVersion. This Excubia app accepts: 1. Update qiick/excubia-core or the app.');
});

it('rejects an oversized payload', function () {
    config(['excubia.push_max_kilobytes' => 1]);
    [, $token] = pushingProject();
    $envelope = pushEnvelope();
    $envelope['report']['upgradePlan'][0]['message'] = str_repeat('x', 2048);

    pushReport($token, $envelope)->assertStatus(413);

    expect(HealthReport::query()->count())->toBe(0);
});

it('rate limits pushes per token', function () {
    config(['excubia.push_requests_per_minute' => 2]);
    [, $token] = pushingProject();

    pushReport($token, pushEnvelope())->assertCreated();
    pushReport($token, pushEnvelope())->assertCreated();
    pushReport($token, pushEnvelope())->assertTooManyRequests();
});

it('stores the release and the deploy time of a report (#106), and reports without them as before', function () {
    [$project, $token] = pushingProject();
    $deploy = pushEnvelope();
    $deploy['report']['release'] = 'v1.4.0+build.7';
    $deploy['report']['deployedAt'] = '2026-11-05T07:59:00Z';

    pushReport($token, $deploy)->assertCreated();
    pushReport($token, pushEnvelope())->assertCreated();

    [$deployed, $plain] = $project->healthReports()->orderBy('id')->get()->all();

    expect($deployed->release)->toBe('v1.4.0+build.7')
        ->and($deployed->deployed_at?->toIso8601ZuluString())->toBe('2026-11-05T07:59:00Z')
        ->and($plain->release)->toBeNull()
        ->and($plain->deployed_at)->toBeNull()
        ->and($project->healthReports()->deploys()->pluck('id')->all())->toBe([$deployed->id]);

    $tooLong = pushEnvelope();
    $tooLong['report']['release'] = str_repeat('a', 101);
    $tooLong['report']['deployedAt'] = 'after lunch';

    pushReport($token, $tooLong)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['report.release', 'report.deployedAt']);
});
