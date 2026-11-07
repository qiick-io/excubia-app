<?php

declare(strict_types=1);

use App\Actions\StorePushedReportAction;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Alerts (#22): Settings → Integrations, the events a report raises, delivery to the signed
 * webhook, Slack and Telegram, and the stale-project alert.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-05 12:00:00');
});

function alertsManager(): User
{
    return User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
}

/**
 * A report with the given findings (all failing), as excubia-core sends it.
 *
 * @param  list<array{0: string, 1: string}>  $findings  [subject, severity]
 * @return array<string, mixed>
 */
function alertReport(array $findings): array
{
    return [
        'schemaVersion' => 1,
        'checks' => [[
            'checkId' => 'composer-audit',
            'name' => 'Composer audit',
            'status' => $findings === [] ? 'pass' : 'fail',
            'findings' => array_map(fn (array $finding): array => [
                'checkId' => 'composer-audit', 'severity' => $finding[1], 'status' => 'fail', 'subject' => $finding[0],
                'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null,
            ], $findings),
        ]],
        'upgradePlan' => [],
    ];
}

function storeAlertReport(Project $project, array $findings): void
{
    app(StorePushedReportAction::class)->execute($project, (string) Str::uuid(), Date::now(), alertReport($findings));
}

function configureAlertChannels(array $overrides = []): void
{
    app(SettingsRepositoryService::class)->save([
        'webhook_url' => 'https://hooks.example.com/excubia',
        'webhook_secret' => str_repeat('s', 32),
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'notifier_minimum_severity' => 'high',
        ...$overrides,
    ]);
}

it('saves the integrations settings, keeps secrets write-only and validates them', function () {
    $manager = alertsManager();

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        'webhook_secret' => str_repeat('a', 32),
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'notifier_telegram_bot_token' => '123456:ABC-def_1',
        'notifier_telegram_chat_id' => '-1001234567890',
        'notifier_event_types' => ['finding.new', 'project.stale'],
        'notifier_minimum_severity' => 'medium',
    ])->assertRedirect(route('project.integrations.edit'));

    $settings = app(SettingsRepositoryService::class);

    expect($settings->webhookSecret())->toBe(str_repeat('a', 32))
        ->and($settings->telegramBotToken())->toBe('123456:ABC-def_1')
        // The Slack incoming-webhook URL is a bearer secret (#51): encrypted at rest.
        ->and($settings->slackWebhookUrl())->toBe('https://hooks.slack.com/services/T/B/X')
        ->and(Setting::query()->where('key', 'notifier_slack_webhook_url')->value('value'))->not->toContain('hooks.slack.com')
        ->and($settings->notifierEventTypes())->toBe(['finding.new', 'project.stale']);

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/project/integrations')
            ->where('project.has_webhook_secret', true)
            ->where('project.has_notifier_telegram_bot_token', true)
            ->missing('project.webhook_secret')
            ->missing('project.notifier_telegram_bot_token')
            ->where('project.has_notifier_slack_webhook_url', true)
            ->missing('project.notifier_slack_webhook_url')
            ->where('project.notifier_minimum_severity', 'medium'));

    // A save with empty secrets keeps them; no event box means no event.
    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        'notifier_minimum_severity' => 'high',
    ]);

    expect(app(SettingsRepositoryService::class)->webhookSecret())->toBe(str_repeat('a', 32))
        ->and(app(SettingsRepositoryService::class)->notifierEventTypes())->toBe([]);

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'http://hooks.example.com',
        'webhook_secret' => 'short',
        'notifier_slack_webhook_url' => 'https://example.com/slack',
        'notifier_telegram_bot_token' => 'MyBot',
        'notifier_event_types' => ['users.deleted'],
        'notifier_minimum_severity' => 'loud',
    ])->assertSessionHasErrors(['webhook_url', 'webhook_secret', 'notifier_slack_webhook_url', 'notifier_telegram_bot_token', 'notifier_event_types.0', 'notifier_minimum_severity']);

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Admin->value))
        ->get(route('project.integrations.edit'))->assertForbidden();
});

it('keeps using a Slack URL saved in plain text before it was encrypted (#51)', function () {
    app(SettingsRepositoryService::class)->setMany(['notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/OLD']);

    expect(app(SettingsRepositoryService::class)->slackWebhookUrl())->toBe('https://hooks.slack.com/services/T/B/OLD')
        ->and(app(SettingsRepositoryService::class)->forEdit()['has_notifier_slack_webhook_url'])->toBeTrue();
});

it('raises new, resolved and worsened alerts by comparing reports', function () {
    Queue::fake();
    configureAlertChannels(['notifier_minimum_severity' => 'info']);
    $project = Project::factory()->create();

    storeAlertReport($project, [['old/package', 'low']]);
    Queue::assertNothingPushed();

    storeAlertReport($project, [['new/package', 'critical']]);

    $events = collect(Queue::pushedJobs()[DeliverAlertJob::class])
        ->map(fn (array $job) => $job['job'])
        ->filter(fn (DeliverAlertJob $job): bool => $job->channel === DeliverAlertJob::WEBHOOK)
        ->map(fn (DeliverAlertJob $job): string => $job->payload['event'].':'.$job->payload['severity'])
        ->values()->all();

    expect($events)->toBe(['finding.new:critical', 'finding.resolved:info', 'project.worsened:critical']);
});

it('sends Slack only the chosen events at or above the minimum severity', function () {
    Queue::fake();
    configureAlertChannels(['notifier_event_types' => ['finding.new'], 'notifier_minimum_severity' => 'high']);
    $project = Project::factory()->create();

    storeAlertReport($project, []);
    storeAlertReport($project, [['medium/package', 'medium'], ['high/package', 'high']]);

    $slack = collect(Queue::pushedJobs()[DeliverAlertJob::class])
        ->map(fn (array $job) => $job['job'])
        ->filter(fn (DeliverAlertJob $job): bool => $job->channel === DeliverAlertJob::SLACK);

    expect($slack)->toHaveCount(1)
        ->and($slack->first()->text)->toContain('[HIGH] finding.new')->toContain('high/package');
});

it('signs webhook deliveries and records the outcome', function () {
    // An IP literal: the address check needs no DNS lookup in tests.
    configureAlertChannels(['webhook_url' => 'https://93.184.216.34/excubia']);
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);
    $eventId = (string) Str::uuid();
    $payload = ['id' => $eventId, 'event' => 'finding.new', 'severity' => 'high', 'project' => null, 'occurredAt' => '2026-11-05T12:00:00Z', 'url' => 'https://excubia.test', 'data' => []];

    (new DeliverAlertJob(DeliverAlertJob::WEBHOOK, $payload))->handle(app(SettingsRepositoryService::class));

    Http::assertSent(function ($request): bool {
        $timestamp = $request->header('X-Excubia-Timestamp')[0];

        return $request->url() === 'https://93.184.216.34/excubia'
            && $request->header('X-Excubia-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), str_repeat('s', 32));
    });
    expect(app(SettingsRepositoryService::class)->all()['webhook_last_success']['eventId'])->toBe($eventId);
});

it('refuses deliveries to private addresses', function () {
    configureAlertChannels(['webhook_url' => 'https://127.0.0.1/hook']);
    Http::fake();

    (new DeliverAlertJob(DeliverAlertJob::WEBHOOK, ['id' => (string) Str::uuid(), 'event' => 'ping']))->handle(app(SettingsRepositoryService::class));

    Http::assertNothingSent();
    expect(app(SettingsRepositoryService::class)->all()['webhook_last_error']['message'])->toContain('Refused');
});

it('refuses IPv6 loopback, private and IPv4-mapped literals (#51)', function (string $url) {
    configureAlertChannels(['webhook_url' => $url]);
    Http::fake();

    (new DeliverAlertJob(DeliverAlertJob::WEBHOOK, ['id' => (string) Str::uuid(), 'event' => 'ping']))->handle(app(SettingsRepositoryService::class));

    Http::assertNothingSent();
    expect(app(SettingsRepositoryService::class)->all()['webhook_last_error']['message'])->toContain('Refused');
})->with([
    'loopback' => 'https://[::1]/hook',
    'unique local' => 'https://[fd00::1]/hook',
    'link local' => 'https://[fe80::1]/hook',
    'mapped loopback' => 'https://[::ffff:127.0.0.1]/hook',
    'metadata' => 'https://169.254.169.254/latest',
    'credentials' => 'https://user:pass@93.184.216.34/hook',
]);

it('never quotes the delivery URL in a transport error, so channel secrets stay out of failed jobs (#51)', function () {
    configureAlertChannels(['webhook_url' => 'https://93.184.216.34/hook?key=url-secret-value']);
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://93.184.216.34/hook?key=url-secret-value'));
    $job = new DeliverAlertJob(DeliverAlertJob::WEBHOOK, ['id' => (string) Str::uuid(), 'event' => 'ping']);

    try {
        $job->handle(app(SettingsRepositoryService::class));
        $this->fail('The delivery should fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Alert delivery to webhook failed: ConnectionException');
        $job->failed($exception);
    }

    expect(json_encode(app(SettingsRepositoryService::class)->all()['webhook_last_error']))->not->toContain('url-secret-value');
});

it('alerts once for a stale project and again when it reports', function () {
    Queue::fake();
    configureAlertChannels();
    $project = Project::factory()->create(['last_report_at' => Date::now()->subDays(3)]);

    $this->artisan('excubia:alert-stale')->assertSuccessful();
    $this->artisan('excubia:alert-stale')->assertSuccessful();

    $webhookEvents = fn (): array => collect(Queue::pushedJobs()[DeliverAlertJob::class] ?? [])
        ->map(fn (array $job) => $job['job'])
        ->filter(fn (DeliverAlertJob $job): bool => $job->channel === DeliverAlertJob::WEBHOOK)
        ->map(fn (DeliverAlertJob $job): string => $job->payload['event'])->values()->all();

    expect($webhookEvents())->toBe(['project.stale']);

    storeAlertReport($project->fresh(), []);

    expect($webhookEvents())->toBe(['project.stale', 'project.reporting_again'])
        ->and($project->fresh()->stale_alerted_at)->toBeNull();
});

it('queues a test event only for a configured channel', function () {
    Queue::fake();
    $manager = alertsManager();

    $this->actingAs($manager)->post(route('project.integrations.test', 'telegram'))
        ->assertInertiaFlash('toast.type', 'error');
    Queue::assertNothingPushed();

    configureAlertChannels();
    $this->actingAs($manager)->post(route('project.integrations.test', 'webhook'))
        ->assertInertiaFlash('toast.type', 'success');
    Queue::assertPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => $job->payload['event'] === 'ping');
});
