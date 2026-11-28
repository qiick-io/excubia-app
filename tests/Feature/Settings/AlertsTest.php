<?php

declare(strict_types=1);

use App\Actions\DeleteUserAction;
use App\Actions\StorePushedReportAction;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Jobs\SendAlertWebhooksJob;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\AlertWebhookService;
use App\Services\ApiKeySecretOnceService;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Qiick\Outbound\Contracts\HostResolver;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookDeliveryAttempt;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Testing\FakeHostResolver;
use Qiick\Outbound\Webhooks\StandardWebhookSigner;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

/**
 * Alerts (#22): Settings → Integrations, the events a report raises, delivery to the signed
 * webhook, Slack and Telegram, and the stale-project alert.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-05 12:00:00');
    // Save-time SSRF validation resolves hostnames; pin public answers for form tests.
    app()->instance(
        HostResolver::class,
        new FakeHostResolver([
            'hooks.example.com' => ['93.184.216.34'],
            'hooks.slack.com' => ['93.184.216.34'],
        ]),
    );
    app()->forgetInstance(RemoteUrlGuard::class);
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

/**
 * The webhook (every event and severity) and Slack (every event from the given severity),
 * plus any other settings.
 *
 * @param  array<string, mixed>  $overrides
 * @param  list<string>|null  $slackEvents  null: every event
 */
function configureAlertChannels(array $overrides = [], string $slackSeverity = 'high', ?array $slackEvents = null): void
{
    $webhookUrl = $overrides['webhook_url'] ?? 'https://hooks.example.com/excubia';
    unset($overrides['webhook_url']);
    configureAlertWebhook($webhookUrl);

    app(SettingsRepositoryService::class)->save([
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'alert_channels' => ['slack' => ['minimum_severity' => $slackSeverity, 'event_types' => $slackEvents]],
        ...$overrides,
    ]);
}

/**
 * The form fields of Settings → Integrations for the channel filters.
 *
 * @param  array<string, array<string, mixed>>  $overrides  channel → filter
 * @return array<string, array<string, mixed>>
 */
function integrationChannels(array $overrides = []): array
{
    $default = ['minimum_severity' => 'high', 'event_types' => ['finding.new']];

    return ['channels' => [
        'webhook' => $overrides['webhook'] ?? ['minimum_severity' => 'info', 'event_types' => ['finding.new']],
        'slack' => $overrides['slack'] ?? $default,
        'telegram' => $overrides['telegram'] ?? $default,
    ]];
}

it('saves only validated values from the integrations settings and drops the rule rows saved before #166', function () {
    // What settings() saved before #166: its rule keys with the rule arrays as values.
    app(SettingsRepositoryService::class)->setMany([
        'channels.slack.minimum_severity' => ['required', 'in:"info","low"'],
        'event_severities' => ['nullable', 'array'],
        'event_severities.*' => [[]],
    ]);

    $this->actingAs(alertsManager())->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        'event_severities' => ['project.stale' => 'low'],
        'alert_log_spike_per_hour' => '50',
        ...integrationChannels(),
    ])->assertSessionHasNoErrors();

    $stored = Setting::query()->pluck('value', 'key');

    expect($stored->keys()->filter(fn (string $key): bool => str_starts_with($key, 'channels.') || str_starts_with($key, 'event_severities')))->toBeEmpty()
        ->and(json_encode($stored->all()))->not->toContain('nullable')->not->toContain('required')->not->toContain('distinct')
        ->and($stored['alert_channels']['slack'])->toMatchArray(['minimum_severity' => 'high', 'event_types' => ['finding.new']])
        ->and($stored['alert_log_spike_per_hour'])->toBe(50)
        ->and(app(SettingsRepositoryService::class)->alertChannelFilter('slack')->eventTypes)->toBe(['finding.new']);
});

it('saves the integrations settings, keeps secrets write-only and validates them', function () {
    $manager = alertsManager();

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'notifier_telegram_bot_token' => '123456:ABC-def_1',
        'notifier_telegram_chat_id' => '-1001234567890',
        ...integrationChannels(['slack' => ['minimum_severity' => 'medium', 'event_types' => ['finding.new', 'project.stale']]]),
    ])->assertRedirect(route('project.integrations.edit'));

    $settings = app(SettingsRepositoryService::class);
    $endpoint = WebhookEndpoint::query()->sole();

    expect($endpoint->url)->toBe('https://hooks.example.com/excubia')
        ->and($endpoint->created_by)->toBe($manager->id)
        ->and($settings->telegramBotToken())->toBe('123456:ABC-def_1')
        // The Slack incoming-webhook URL is a bearer secret (#51): encrypted at rest.
        ->and($settings->slackWebhookUrl())->toBe('https://hooks.slack.com/services/T/B/X')
        ->and(Setting::query()->where('key', 'notifier_slack_webhook_url')->value('value'))->not->toContain('hooks.slack.com')
        ->and($settings->alertChannelFilter('slack')->eventTypes)->toBe(['finding.new', 'project.stale']);

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/project/integrations')
            ->where('project.webhook_url', 'https://hooks.example.com/excubia')
            ->where('project.has_webhook_secret', false)
            ->where('project.has_notifier_telegram_bot_token', true)
            ->missing('project.notifier_telegram_bot_token')
            ->where('project.has_notifier_slack_webhook_url', true)
            ->missing('project.notifier_slack_webhook_url')
            ->where('project.channels.slack.minimum_severity', 'medium')
            ->where('project.channels.webhook.minimum_severity', 'info'));

    // No event box means no event; the endpoint keeps its URL.
    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        ...integrationChannels(['slack' => ['minimum_severity' => 'high']]),
    ]);

    expect(WebhookEndpoint::query()->sole()->is($endpoint))->toBeTrue()
        ->and(app(SettingsRepositoryService::class)->alertChannelFilter('slack')->eventTypes)->toBe([]);

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'http://hooks.example.com',
        'notifier_slack_webhook_url' => 'https://example.com/slack',
        'notifier_telegram_bot_token' => 'MyBot',
        ...integrationChannels(['slack' => ['minimum_severity' => 'loud', 'event_types' => ['users.deleted'], 'project_ids' => [999]]]),
    ])->assertSessionHasErrors(['webhook_url', 'notifier_slack_webhook_url', 'notifier_telegram_bot_token', 'channels.slack.event_types.0', 'channels.slack.minimum_severity', 'channels.slack.project_ids.0']);

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://1..2/hook',
        ...integrationChannels(),
    ])->assertSessionHasErrors(['webhook_url']);

    app()->instance(
        HostResolver::class,
        new FakeHostResolver(['hooks.slack.com' => ['10.0.0.8']]),
    );
    app()->forgetInstance(RemoteUrlGuard::class);

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://93.184.216.34/excubia',
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/PRIVATE',
        ...integrationChannels(),
    ])->assertSessionHasErrors(['notifier_slack_webhook_url']);

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Reader->value))
        ->get(route('project.integrations.edit'))->assertForbidden();
});

it('generates the webhook signing secret, shows it once and rotates it with a grace period', function () {
    $manager = alertsManager();
    $this->actingAs($manager)->post(route('project.integrations.webhook-secret'))
        ->assertRedirect(route('project.integrations.edit'))
        ->assertInertiaFlash('toast.type', 'error');

    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        ...integrationChannels(),
    ]);
    $this->actingAs($manager)->post(route('project.integrations.webhook-secret'))->assertRedirect(route('project.integrations.edit'));
    $first = pendingIssuedApiKeySecret(ApiKeySecretOnceService::WEBHOOK_SESSION_KEY);

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.has_webhook_secret', true)
            ->hasFlash('issuedWebhookSecret.secret', $first)
            ->hasFlash('issuedWebhookSecret.reason', 'created'));

    expect($first)->toStartWith('whsec_')
        ->and(json_encode(DB::table('webhook_endpoint_secrets')->get()))->not->toContain($first)
        ->and(json_encode(Activity::query()->get()))->not->toContain($first)
        ->and(Activity::query()->where('event', 'webhook_secret_created')->count())->toBe(1);

    // Shown once: the next load has no secret.
    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('issuedWebhookSecret'));

    $this->actingAs($manager)->post(route('project.integrations.webhook-secret'));
    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('issuedWebhookSecret.reason', 'rotated'));

    // The previous secret keeps signing during the rotation grace.
    $signingSet = app(WebhookSecretResolver::class)->signingSetFor(WebhookEndpoint::query()->sole());

    expect($signingSet->secretPublicIds())->toHaveCount(2)
        ->and(Activity::query()->where('event', 'webhook_secret_rotated')->count())->toBe(1);
});

it('keeps the plaintext signing secret out of the session when the page fails to render', function () {
    $manager = alertsManager();
    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        ...integrationChannels(),
    ]);
    $this->actingAs($manager)->post(route('project.integrations.webhook-secret'));
    // A shared prop that throws (a database error in the notification count) fails the render
    // after the page took the secret: Laravel still saves the session with the error page.
    Inertia::share('explodes', fn () => throw new RuntimeException('render failed'));

    $this->actingAs($manager)->get(route('project.integrations.edit'))->assertServerError();

    expect(json_encode(session()->all()))->not->toContain('whsec_');
});

it('shows the signing secret to one page only when two requests loaded the same session', function () {
    $manager = alertsManager();
    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        ...integrationChannels(),
    ]);
    $this->actingAs($manager)->post(route('project.integrations.webhook-secret'));
    $staleSessionValue = session()->get(ApiKeySecretOnceService::WEBHOOK_SESSION_KEY);

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('issuedWebhookSecret.reason', 'created'));

    // The second request read the session before the first one saved it.
    session()->put(ApiKeySecretOnceService::WEBHOOK_SESSION_KEY, $staleSessionValue);

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('issuedWebhookSecret'));
});

it('does not consume the signing secret on a prefetch', function () {
    $manager = alertsManager();
    $this->actingAs($manager)->put(route('project.integrations.update'), [
        'webhook_url' => 'https://hooks.example.com/excubia',
        ...integrationChannels(),
    ]);
    $this->actingAs($manager)->post(route('project.integrations.webhook-secret'));

    $this->actingAs($manager)->withHeaders(['Purpose' => 'prefetch'])->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->missingFlash('issuedWebhookSecret'));
    $this->flushHeaders();

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->hasFlash('issuedWebhookSecret.reason', 'created'));
});

it('keeps using a Slack URL saved in plain text before it was encrypted (#51)', function () {
    app(SettingsRepositoryService::class)->setMany(['notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/OLD']);

    expect(app(SettingsRepositoryService::class)->slackWebhookUrl())->toBe('https://hooks.slack.com/services/T/B/OLD')
        ->and(app(SettingsRepositoryService::class)->forEdit()['has_notifier_slack_webhook_url'])->toBeTrue();
});

it('raises new, resolved and worsened alerts by comparing reports', function () {
    Queue::fake();
    configureAlertChannels(slackSeverity: 'info');
    $project = Project::factory()->create();

    storeAlertReport($project, [['old/package', 'low']]);
    Queue::assertNothingPushed();

    storeAlertReport($project, [['new/package', 'critical']]);

    $events = WebhookDelivery::query()->orderBy('id')->get()
        ->map(function (WebhookDelivery $delivery): string {
            $body = json_decode((string) $delivery->payload, true, 512, JSON_THROW_ON_ERROR);

            return $body['type'].':'.$body['data']['severity'];
        })
        ->all();

    expect($events)->toBe(['finding.new:critical', 'finding.resolved:info', 'project.worsened:critical']);
});

it('sends Slack only the chosen events at or above the minimum severity', function () {
    Queue::fake();
    configureAlertChannels(slackSeverity: 'high', slackEvents: ['finding.new']);
    $project = Project::factory()->create();

    storeAlertReport($project, []);
    storeAlertReport($project, [['medium/package', 'medium'], ['high/package', 'high']]);

    $slack = collect(Queue::pushedJobs()[DeliverAlertJob::class])
        ->map(fn (array $job) => $job['job'])
        ->filter(fn (DeliverAlertJob $job): bool => $job->channel === DeliverAlertJob::SLACK);

    expect($slack)->toHaveCount(1)
        ->and($slack->first()->text)->toContain('[HIGH] finding.new')->toContain('high/package');
});

/**
 * Sends every stored alert webhook delivery once, as the queued worker runs do.
 */
function runAlertWebhooks(): void
{
    foreach (range(1, max(1, WebhookDelivery::query()->count())) as $run) {
        app()->call([new SendAlertWebhooksJob, 'handle']);
    }
}

it('signs webhook deliveries and records the outcome', function () {
    // An IP literal: the address check needs no DNS lookup in tests.
    configureAlertChannels(['webhook_url' => 'https://93.184.216.34/excubia']);
    $secret = app(WebhookSecretResolver::class)->signingSetFor(WebhookEndpoint::query()->sole())->secrets();
    $captured = null;
    bindPinnedAlertClient(app(HostResolver::class), function (Request $request) use (&$captured) {
        $captured = $request;

        return Http::response('', 204);
    });
    $eventId = (string) Str::uuid();

    app(AlertWebhookService::class)->send(['id' => $eventId, 'event' => 'finding.new', 'severity' => 'high', 'project' => null, 'occurredAt' => '2026-11-05T12:00:00Z', 'url' => 'https://excubia.test', 'data' => []]);
    runAlertWebhooks();

    expect($captured)->not->toBeNull()
        ->and($captured->url())->toBe('https://93.184.216.34/excubia')
        ->and($captured->header('Webhook-Id')[0])->toBe($eventId)
        ->and($captured->hasHeader('X-Excubia-Signature'))->toBeFalse();

    $body = json_decode($captured->body(), true, 512, JSON_THROW_ON_ERROR);
    $signed = app(StandardWebhookSigner::class)->sign(
        $eventId,
        (int) $captured->header('Webhook-Timestamp')[0],
        $captured->body(),
        $secret,
    );

    expect($body)->toHaveKeys(['type', 'timestamp', 'data'])
        ->and($body['type'])->toBe('finding.new')
        ->and($captured->header('Webhook-Signature')[0])->toBe($signed->headers['webhook-signature'])
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Delivered)
        ->and(WebhookEndpoint::query()->sole()->last_success_at)->not->toBeNull();
});

it('refuses deliveries to private addresses', function (string $url) {
    configureAlertChannels(['webhook_url' => $url]);
    bindPinnedAlertClient(app(HostResolver::class), static fn () => Http::response('', 204));

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'ping']);
    runAlertWebhooks();

    $attempt = WebhookDeliveryAttempt::query()->sole();

    expect($attempt->http_status)->toBeNull()
        ->and($attempt->error_code)->toBe('ssrf_refused')
        ->and(WebhookDelivery::query()->sole()->delivered_at)->toBeNull();
})->with([
    'loopback v4' => 'https://127.0.0.1/hook',
    // #51: IPv6 loopback, private and IPv4-mapped literals, metadata and credentials.
    'loopback' => 'https://[::1]/hook',
    'unique local' => 'https://[fd00::1]/hook',
    'link local' => 'https://[fe80::1]/hook',
    'mapped loopback' => 'https://[::ffff:127.0.0.1]/hook',
    'metadata' => 'https://169.254.169.254/latest',
    'credentials' => 'https://user:pass@93.184.216.34/hook',
]);

it('never quotes the delivery URL in a transport error, so channel secrets stay out of the records (#51)', function () {
    configureAlertChannels(['webhook_url' => 'https://93.184.216.34/hook?key=url-secret-value']);
    bindPinnedAlertClient(app(HostResolver::class), static fn () => throw new ConnectionException('cURL error 28: timed out for https://93.184.216.34/hook?key=url-secret-value'));

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'ping']);
    runAlertWebhooks();

    expect(WebhookDeliveryAttempt::query()->sole()->error_code)->not->toBeNull()
        ->and(json_encode(DB::table('webhook_delivery_attempts')->get()))->not->toContain('url-secret-value')
        ->and(json_encode(DB::table('webhook_deliveries')->get()))->not->toContain('url-secret-value');
});

it('alerts once for a stale project and again when it reports', function () {
    Queue::fake();
    configureAlertChannels();
    $project = Project::factory()->create(['last_report_at' => Date::now()->subDays(3)]);

    $this->artisan('excubia:alert-stale')->assertSuccessful();
    $this->artisan('excubia:alert-stale')->assertSuccessful();

    $webhookEvents = fn (): array => alertWebhookEvents();

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
    Queue::assertPushed(SendAlertWebhooksJob::class);

    expect(alertWebhookEvents())->toBe(['ping']);
});

it('shows a webhook the package disabled and turns it back on', function () {
    $manager = alertsManager();
    configureAlertWebhook('https://93.184.216.34/excubia');
    $endpoint = WebhookEndpoint::query()->sole();
    app(WebhookDeliveryCoordinator::class)->disableEndpoint($endpoint);

    expect(app(AlertWebhookService::class)->isReady())->toBeFalse();

    $this->actingAs($manager)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.webhook_status.is_active', false)
            ->where('project.has_webhook_secret', true));

    $this->actingAs($manager)->post(route('project.integrations.webhook-enable'))
        ->assertRedirect(route('project.integrations.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($endpoint->fresh()->is_active)->toBeTrue()
        ->and(app(AlertWebhookService::class)->isReady())->toBeTrue();
});

it('escapes Slack mrkdwn control sequences in the alert text', function () {
    // #477: pushed report text could add `<!channel>` mentions and disguised links.
    configureAlertChannels();
    $captured = null;
    bindPinnedAlertClient(app(HostResolver::class), function (Request $request) use (&$captured) {
        $captured = $request;

        return Http::response('ok', 200);
    });

    (new DeliverAlertJob(DeliverAlertJob::SLACK, ['id' => (string) Str::uuid(), 'event' => 'finding.new', 'severity' => 'high', 'project' => null], 'Known vulnerability <!channel> <https://phishing.example|Open in Excubia> & more'))
        ->handle(app(SettingsRepositoryService::class), app(PinnedHttpClient::class));

    expect(json_decode((string) $captured?->body(), true)['text'] ?? null)
        ->toBe('Known vulnerability &lt;!channel&gt; &lt;https://phishing.example|Open in Excubia&gt; &amp; more');
});

it('names the assignee as the subject of a webhook delivery, and erasing them cancels it and drops its body', function () {
    configureAlertChannels(['webhook_url' => 'https://93.184.216.34/excubia']);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $assignee = User::factory()->create();

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'finding.new', 'data' => ['assignee' => $assignee->name]], $assignee->public_id);
    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->subject_public_id)->toBe($assignee->public_id)
        ->and($delivery->status)->toBe(DeliveryStatus::Pending);

    app(DeleteUserAction::class)->execute($assignee);
    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Cancelled)
        ->and($delivery->subject_public_id)->toBeNull()
        ->and($delivery->getRawOriginal('payload'))->toBeNull();
});

it('leaves the name and id of an assignee who was erased in between out of a new delivery', function () {
    configureAlertChannels(['webhook_url' => 'https://93.184.216.34/excubia']);
    User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $assignee = User::factory()->create();
    $name = $assignee->name;
    // The report read the assignee before the erasure; it stores its alert after.
    app(DeleteUserAction::class)->execute($assignee);

    app(AlertWebhookService::class)->send(['id' => (string) Str::uuid(), 'event' => 'finding.new', 'data' => (object) ['assignee' => $name, 'subject' => 'vendor/package']], $assignee->public_id);
    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->subject_public_id)->toBeNull()
        ->and(Crypt::decryptString((string) $delivery->getRawOriginal('payload')))->not->toContain($name)
        ->and(Crypt::decryptString((string) $delivery->getRawOriginal('payload')))->toContain('vendor/package');
});
