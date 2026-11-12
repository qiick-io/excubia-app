<?php

declare(strict_types=1);

use App\Actions\StorePushedReportAction;
use App\Enums\AlertEventEnum;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * End-of-life alerts (#157): `eol.approaching` once per threshold (90, 30, 7 days) and
 * `eol.reached` once per runtime cycle, after a report and from the daily command, on every
 * channel, and quiet again once the project upgrades.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Queue::fake();
    Date::setTestNow('2026-11-07 12:00:00');

    app(SettingsRepositoryService::class)->save([
        'webhook_url' => 'https://93.184.216.34/excubia',
        'webhook_secret' => str_repeat('s', 32),
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'notifier_telegram_bot_token' => '123456:ABC-def_1',
        'notifier_telegram_chat_id' => '-1001234567890',
        'alert_channels' => [
            'slack' => ['minimum_severity' => 'info', 'event_types' => null],
            'telegram' => ['minimum_severity' => 'info', 'event_types' => null],
        ],
    ]);

    $subscriber = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $subscriber->forceFill(['has_alert_emails' => true, 'alert_email_filters' => ['minimum_severity' => 'info', 'event_types' => null, 'project_ids' => []]])->save();
});

/**
 * Stores a report whose PHP cycle loses security support on `$endsOn`.
 */
function storeEolReport(Project $project, string $endsOn, string $cycle = '8.2'): void
{
    app(StorePushedReportAction::class)->execute($project, (string) Str::uuid(), Date::now(), [
        'schemaVersion' => 1,
        'project' => [
            'php' => ['version' => "{$cycle}.10", 'cycle' => $cycle, 'securitySupportEndsOn' => $endsOn, 'activeSupportEndsOn' => null],
            'node' => null,
        ],
        'checks' => [],
        'upgradePlan' => [],
    ]);
}

/**
 * @return list<string> "event subject severity" of the project's EOL alerts that went out, oldest first
 */
function eolAlerts(Project $project): array
{
    return AlertLog::query()->where('project_id', $project->id)->where('event', 'like', 'eol.%')->where('status', AlertLog::QUEUED)->orderBy('id')->get()
        ->map(fn (AlertLog $alert): string => "{$alert->event} {$alert->subject} {$alert->severity}")
        ->all();
}

it('sends :dataset to the webhook, Slack, Telegram and every subscriber', function (string $event, string $endsOn) {
    storeEolReport(Project::factory()->create(), $endsOn);

    $byChannel = [];

    foreach (Queue::pushedJobs()[DeliverAlertJob::class] ?? [] as $pushed) {
        $job = $pushed['job'];
        $channel = match (true) {
            DeliverAlertJob::emailUserId($job->channel) !== null => DeliverAlertJob::EMAIL,
            DeliverAlertJob::appUserId($job->channel) !== null => DeliverAlertJob::APP,
            default => $job->channel,
        };
        $byChannel[$channel] = [...$byChannel[$channel] ?? [], ...($job->alerts === [] ? [(string) $job->payload['event']] : array_column($job->alerts, 'event'))];
    }

    // The subscriber's in-app alerts (#296) are on by default and read the same filters.
    expect(array_keys($byChannel))->toEqualCanonicalizing([DeliverAlertJob::WEBHOOK, DeliverAlertJob::SLACK, DeliverAlertJob::TELEGRAM, DeliverAlertJob::EMAIL, DeliverAlertJob::APP]);

    foreach ($byChannel as $channel => $events) {
        expect(in_array($event, $events, true))->toBeTrue("{$event} did not reach {$channel}");
    }
})->with([
    'eol.approaching' => ['eol.approaching', '2026-12-31'],
    'eol.reached' => ['eol.reached', '2026-11-01'],
]);

it('alerts each threshold once, the tightest one reached, high from 7 days', function () {
    $project = Project::factory()->create();

    // 120 days left: no threshold yet.
    storeEolReport($project, '2027-03-07');
    expect(eolAlerts($project))->toBe([]);

    // 54 days left: the 90-day threshold, once, on reports and on the daily run.
    storeEolReport($project, '2026-12-31');
    storeEolReport($project, '2026-12-31');
    $this->artisan('excubia:alert-eol')->assertSuccessful();
    expect(eolAlerts($project))->toBe(['eol.approaching php:8.2:90 medium']);

    // The calendar crosses 30 days without a new report: the daily run sends it.
    Date::setTestNow('2026-12-02 12:00:00');
    $this->artisan('excubia:alert-eol')->assertSuccessful();
    $this->artisan('excubia:alert-eol')->assertSuccessful();

    // 5 days left: 7 at high, then the end.
    Date::setTestNow('2026-12-26 12:00:00');
    $this->artisan('excubia:alert-eol')->assertSuccessful();
    Date::setTestNow('2026-12-31 12:00:00');
    $this->artisan('excubia:alert-eol')->assertSuccessful();
    $this->artisan('excubia:alert-eol')->assertSuccessful();

    expect(eolAlerts($project))->toBe([
        'eol.approaching php:8.2:90 medium',
        'eol.approaching php:8.2:30 medium',
        'eol.approaching php:8.2:7 high',
        'eol.reached php:8.2:reached high',
    ]);

    $payload = collect(Queue::pushedJobs()[DeliverAlertJob::class])->map(fn (array $pushed): array => $pushed['job']->payload)
        ->first(fn (array $payload): bool => $payload['event'] === 'eol.reached' && $payload['severity'] === 'high');
    expect((array) $payload['data'])->toBe(['runtime' => 'php', 'version' => '8.2.10', 'cycle' => '8.2', 'securitySupportEndsOn' => '2026-12-31', 'daysLeft' => 0, 'thresholdDays' => null]);
});

it('sends only the tightest threshold when a project first reports close to the end', function () {
    $project = Project::factory()->create();
    storeEolReport($project, '2026-11-12');

    expect(eolAlerts($project))->toBe(['eol.approaching php:8.2:7 high']);
});

it('stops alerting once the project upgrades to a supported cycle', function () {
    $project = Project::factory()->create();
    storeEolReport($project, '2026-11-01');
    storeEolReport($project, '2028-12-31', '8.4');
    $this->artisan('excubia:alert-eol')->assertSuccessful();
    Date::setTestNow('2026-11-20 12:00:00');
    $this->artisan('excubia:alert-eol')->assertSuccessful();

    expect(eolAlerts($project))->toBe(['eol.reached php:8.2:reached high']);
});

it('follows the configured lead times and the admin severity', function () {
    config(['excubia.eol_alert_days' => [14]]);
    app(SettingsRepositoryService::class)->save(['alert_event_severities' => ['eol.approaching' => 'low']]);
    $project = Project::factory()->create();
    storeEolReport($project, '2026-12-31');
    storeEolReport($project, '2026-11-19');

    expect(eolAlerts($project))->toBe(['eol.approaching php:8.2:14 low']);
});

it('keeps a muted alert muted, and retries one held back by a maintenance window', function () {
    $muted = Project::factory()->create();
    AlertMute::query()->create(['project_id' => $muted->id, 'event' => AlertEventEnum::EolReached->value, 'until' => Date::now()->addDay()]);
    storeEolReport($muted, '2026-11-01');

    $inMaintenance = Project::factory()->create();
    $window = MaintenanceWindow::query()->create([
        'project_id' => $inMaintenance->id, 'name' => 'Upgrade', 'recurrence' => MaintenanceWindow::ONCE,
        'starts_at' => Date::now()->subHour(), 'ends_at' => Date::now()->addHour(), 'timezone' => 'UTC',
    ]);
    storeEolReport($inMaintenance, '2026-11-01');
    expect(eolAlerts($inMaintenance))->toBe([]);

    Date::setTestNow('2026-11-08 12:00:00');
    $this->artisan('excubia:alert-eol')->assertSuccessful();
    $this->artisan('excubia:alert-eol')->assertSuccessful();

    expect(eolAlerts($muted))->toBe([])
        ->and(AlertLog::query()->where('project_id', $muted->id)->pluck('reason')->all())->toBe(['muted'])
        ->and(eolAlerts($inMaintenance))->toBe(['eol.reached php:8.2:reached high'])
        ->and($window->exists)->toBeTrue();
});

it('lets channels and subscribers choose the EOL events', function () {
    expect(AlertEventEnum::selectableValues())->toContain('eol.approaching', 'eol.reached')
        ->and(AlertEventEnum::severityEditableValues())->toContain('eol.approaching', 'eol.reached');
});
