<?php

declare(strict_types=1);

use App\Actions\CreateProjectAction;
use App\Actions\DeleteProjectAction;
use App\Actions\RevokeProjectTokenAction;
use App\Actions\RotateProjectTokenAction;
use App\Actions\StorePushedReportAction;
use App\Actions\UpdateProjectAction;
use App\Enums\AlertEventEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Jobs\DeliverAlertJob;
use App\Mail\AlertMail;
use App\Models\AlertLog;
use App\Models\IngestRejection;
use App\Models\Project;
use App\Models\User;
use App\Services\AlertDispatcherService;
use App\Services\AlertSuppressionService;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Alerts (#22), third part: every event of the Reports, Reporting and Administration groups on
 * every channel (webhook, Slack, Telegram, email), one message per report and channel, and the
 * hourly throttle. The weekly digest is covered by tests/Feature/Security/DigestMailTest.php.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Queue::fake();
    Date::setTestNow('2026-11-07 12:00:00');

    // Every channel, every event, from `info`. The webhook host is an IP literal: the address
    // check needs no DNS lookup in tests.
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

    $this->subscriber = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->subscriber->forceFill(['has_alert_emails' => true, 'alert_email_filters' => ['minimum_severity' => 'info', 'event_types' => null, 'project_ids' => []]])->save();
});

/**
 * A report as excubia-core sends it: failing findings of composer-audit and, optionally, a
 * check skipped because its data source failed.
 *
 * @param  list<array{0: string, 1: string}>  $findings  [subject, severity]
 * @param  list<string>  $failedSources  check ids skipped with `data-source-failure`
 * @return array<string, mixed>
 */
function eventsReport(array $findings, array $failedSources = []): array
{
    $checks = [[
        'checkId' => 'composer-audit',
        'name' => 'Composer audit',
        'status' => $findings === [] ? 'pass' : 'fail',
        'findings' => array_map(fn (array $finding): array => [
            'checkId' => 'composer-audit', 'severity' => $finding[1], 'status' => 'fail', 'subject' => $finding[0],
            'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null,
        ], $findings),
    ]];

    foreach ($failedSources as $checkId) {
        $checks[] = [
            'checkId' => $checkId,
            'name' => Str::headline($checkId),
            'status' => 'skipped',
            'findings' => [[
                'checkId' => $checkId, 'severity' => 'info', 'status' => 'skipped', 'subject' => '',
                'message' => 'Data source failed', 'fixHint' => '', 'evidenceUrl' => null, 'skipReason' => 'data-source-failure',
            ]],
        ];
    }

    return ['schemaVersion' => 1, 'checks' => $checks, 'upgradePlan' => []];
}

/**
 * @param  list<array{0: string, 1: string}>  $findings
 * @param  list<string>  $failedSources
 */
function storeEventsReport(Project $project, array $findings, array $failedSources = []): void
{
    app(StorePushedReportAction::class)->execute($project, (string) Str::uuid(), Date::now(), eventsReport($findings, $failedSources));
}

/**
 * @return list<DeliverAlertJob>
 */
function eventsJobs(): array
{
    return collect(Queue::pushedJobs()[DeliverAlertJob::class] ?? [])->map(fn (array $job): DeliverAlertJob => $job['job'])->values()->all();
}

/**
 * The events each channel was sent, grouped messages unfolded; `email:<id>` folds into `email`.
 *
 * @return array<string, list<string>>
 */
function eventsByChannel(): array
{
    $byChannel = [];

    foreach (eventsJobs() as $job) {
        $channel = DeliverAlertJob::emailUserId($job->channel) === null ? $job->channel : DeliverAlertJob::EMAIL;
        $events = $job->alerts === [] ? [(string) $job->payload['event']] : array_column($job->alerts, 'event');
        $byChannel[$channel] = [...$byChannel[$channel] ?? [], ...$events];
    }

    return $byChannel;
}

function clearEventsQueue(): void
{
    Queue::fake();
}

/**
 * @return list<string> "event:status[:reason]" of the project's alerts, oldest first
 */
function eventsHistory(Project $project): array
{
    return AlertLog::query()->where('project_id', $project->id)->orderBy('id')->get()
        ->map(fn (AlertLog $alert): string => rtrim("{$alert->event}:{$alert->status}:{$alert->reason}", ':'))
        ->all();
}

function pushWithToken(?string $token): void
{
    test()->postJson(route('api.reports.store'), [], $token === null ? [] : ['Authorization' => "Bearer {$token}"]);
}

it('sends :dataset to the webhook, Slack, Telegram and every subscriber', function (string $event, Closure $raise) {
    $raise();

    expect(eventsByChannel())->toHaveKeys([DeliverAlertJob::WEBHOOK, DeliverAlertJob::SLACK, DeliverAlertJob::TELEGRAM, DeliverAlertJob::EMAIL]);

    foreach (eventsByChannel() as $channel => $events) {
        expect(in_array($event, $events, true))->toBeTrue("{$event} did not reach {$channel}");
    }
})->with([
    // Reports
    'finding.new' => ['finding.new', function () {
        $project = Project::factory()->create();
        storeEventsReport($project, []);
        storeEventsReport($project, [['laravel/framework', 'high']]);
    }],
    'finding.resolved' => ['finding.resolved', function () {
        $project = Project::factory()->create();
        storeEventsReport($project, [['laravel/framework', 'high']]);
        storeEventsReport($project, []);
    }],
    'project.worsened' => ['project.worsened', function () {
        $project = Project::factory()->create();
        storeEventsReport($project, [['laravel/framework', 'low']]);
        storeEventsReport($project, [['laravel/framework', 'low'], ['guzzlehttp/guzzle', 'critical']]);
    }],
    'project.recovered' => ['project.recovered', function () {
        $project = Project::factory()->create();
        storeEventsReport($project, [['laravel/framework', 'critical']]);
        storeEventsReport($project, []);
    }],
    'report.checks_skipped' => ['report.checks_skipped', function () {
        $project = Project::factory()->create();
        storeEventsReport($project, []);
        storeEventsReport($project, [], ['composer-advisories']);
    }],
    // Reporting
    'project.stale' => ['project.stale', function () {
        Project::factory()->create(['last_report_at' => Date::now()->subDays(3)]);
        test()->artisan('excubia:alert-stale')->assertSuccessful();
    }],
    'project.reporting_again' => ['project.reporting_again', function () {
        $project = Project::factory()->create(['last_report_at' => Date::now()->subDays(3), 'stale_alerted_at' => Date::now()->subDays(1)]);
        storeEventsReport($project, []);
    }],
    'push.rejected' => ['push.rejected', function () {
        Project::factory()->create(['is_system' => true]);

        foreach (range(1, 10) as $attempt) {
            pushWithToken('excubia_'.Str::random(40));
        }
    }],
    // Administration
    'project.token_changed' => ['project.token_changed', fn () => app(RotateProjectTokenAction::class)->execute(Project::factory()->create())],
    'project.created' => ['project.created', fn () => app(CreateProjectAction::class)->execute('Shop', 'shop', true)],
    'project.deleted' => ['project.deleted', fn () => app(DeleteProjectAction::class)->execute(Project::factory()->create())],
    'project.disabled' => ['project.disabled', function () {
        $project = Project::factory()->create();
        app(UpdateProjectAction::class)->execute($project, $project->name, false);
    }],
]);

it('delivers every event to the signed webhook and by email, one webhook request per event', function () {
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);
    Mail::fake();
    $project = Project::factory()->create();
    storeEventsReport($project, []);
    storeEventsReport($project, [['laravel/framework', 'high']], ['composer-advisories']);
    app(RevokeProjectTokenAction::class)->execute($project);
    app(UpdateProjectAction::class)->execute($project, $project->name, false);

    foreach (eventsJobs() as $job) {
        if (in_array($job->channel, [DeliverAlertJob::SLACK, DeliverAlertJob::TELEGRAM], true)) {
            continue;
        }

        $job->handle(app(SettingsRepositoryService::class));
    }

    $sent = collect(Http::recorded())->map(fn (array $pair): array => json_decode($pair[0]->body(), true));

    expect($sent->pluck('event')->all())->toBe(['report.checks_skipped', 'finding.new', 'project.worsened', 'project.token_changed', 'project.disabled'])
        ->and($sent->pluck('id')->unique())->toHaveCount(5)
        ->and($sent->first()['data'])->toBe(['checkIds' => ['composer-advisories'], 'count' => 1])
        ->and(AlertLog::query()->get()->every(fn (AlertLog $log): bool => $log->deliveries[DeliverAlertJob::WEBHOOK] === AlertLog::SENT
            && $log->deliveries['email:'.$this->subscriber->id] === AlertLog::SENT))->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('X-Excubia-Signature')[0], 'sha256='));
    // The report's three alerts in one mail, then the token and the disable on their own.
    Mail::assertSentCount(3);
    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => count($mail->alerts) === 3
        && $mail->envelope()->subject === "[HIGH] 3 alerts · {$project->name}");
});

it('sends one Slack, Telegram and email message per report, worst first, but one webhook request per alert', function () {
    $project = Project::factory()->create(['name' => 'Shop']);
    storeEventsReport($project, [['old/package', 'low']]);
    clearEventsQueue();

    storeEventsReport($project, [['a/package', 'medium'], ['b/package', 'critical'], ['c/package', 'high']]);

    $jobs = collect(eventsJobs());
    $slack = $jobs->where('channel', DeliverAlertJob::SLACK)->sole();

    expect($jobs->where('channel', DeliverAlertJob::WEBHOOK))->toHaveCount(5)
        ->and($jobs->where('channel', DeliverAlertJob::TELEGRAM))->toHaveCount(1)
        ->and($jobs->filter(fn (DeliverAlertJob $job): bool => DeliverAlertJob::emailUserId($job->channel) !== null))->toHaveCount(1)
        ->and(array_column($slack->alerts, 'severity'))->toBe(['critical', 'critical', 'high', 'medium', 'info'])
        ->and($slack->payload['event'])->toBe('finding.new')
        ->and($slack->text)->toStartWith('[CRITICAL] Shop: 5 alerts'.PHP_EOL.'[CRITICAL] finding.new: b/package: Known vulnerability')
        ->toContain('[INFO] finding.resolved: old/package')
        ->toEndWith(route('projects.show', $project->slug));

    // Every alert keeps its history row; a grouped delivery's outcome lands on all of them.
    Mail::fake();
    $jobs->first(fn (DeliverAlertJob $job): bool => DeliverAlertJob::emailUserId($job->channel) !== null)->handle(app(SettingsRepositoryService::class));
    $slack->failed(new RuntimeException('HTTP 500'));

    expect(AlertLog::query()->where('project_id', $project->id)->get()->map(fn (AlertLog $log): array => [$log->deliveries['email:'.$this->subscriber->id], $log->deliveries['slack']])->unique()->all())
        ->toBe([[AlertLog::SENT, AlertLog::FAILED]]);
});

it('keeps the one-line message when a report raises a single alert, and caps a long grouped message', function () {
    $project = Project::factory()->create(['name' => 'Shop']);
    storeEventsReport($project, [['old/package', 'high']]);
    clearEventsQueue();
    storeEventsReport($project, [['old/package', 'high'], ['a/package', 'high']]);

    $single = collect(eventsJobs())->where('channel', DeliverAlertJob::TELEGRAM)->sole();

    expect($single->alerts)->toBe([])
        ->and($single->text)->toBe('[HIGH] finding.new · Shop: a/package: Known vulnerability'.PHP_EOL.route('projects.show', $project->slug));

    clearEventsQueue();
    storeEventsReport($project, [['old/package', 'high'], ...array_map(fn (int $index): array => ["package/{$index}", 'high'], range(1, 18))]);

    $grouped = collect(eventsJobs())->where('channel', DeliverAlertJob::TELEGRAM)->sole();

    expect($grouped->alerts)->toHaveCount(19)
        ->and(substr_count($grouped->text, PHP_EOL))->toBe(AlertDispatcherService::GROUPED_LINES + 2)
        ->and($grouped->text)->toContain('… and 4 more');
});

it('sends the same event, project and subject at most once an hour, unless it gets worse', function () {
    $project = Project::factory()->create();
    $dispatch = fn (AlertEventEnum $event, ?string $subject = null, ?SeverityEnum $severity = null) => app(AlertDispatcherService::class)
        ->dispatch($event, $project, 'summary', severity: $severity, subject: $subject);

    $dispatch(AlertEventEnum::ScheduleFailed, 'task-a');
    $dispatch(AlertEventEnum::ScheduleFailed, 'task-a');
    $dispatch(AlertEventEnum::ScheduleFailed, 'task-b');
    $dispatch(AlertEventEnum::ProjectWorsened, severity: SeverityEnum::Medium);
    $dispatch(AlertEventEnum::ProjectWorsened, severity: SeverityEnum::Low);
    $dispatch(AlertEventEnum::ProjectWorsened, severity: SeverityEnum::Critical);
    // A security event is never throttled.
    $dispatch(AlertEventEnum::ProjectTokenChanged);
    $dispatch(AlertEventEnum::ProjectTokenChanged);

    Date::setTestNow(Date::now()->addMinutes(61));
    $dispatch(AlertEventEnum::ScheduleFailed, 'task-a');

    expect(eventsHistory($project))->toBe([
        'schedule.failed:queued',
        'schedule.failed:suppressed:throttled',
        'schedule.failed:queued',
        'project.worsened:queued',
        'project.worsened:suppressed:throttled',
        'project.worsened:queued',
        'project.token_changed:queued',
        'project.token_changed:queued',
        'schedule.failed:queued',
    ]);
    expect(collect(eventsJobs())->where('channel', DeliverAlertJob::WEBHOOK))->toHaveCount(7);
});

it('throttles only alerts that reached a channel, and can be turned off', function () {
    $project = Project::factory()->create();
    $stale = fn () => app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');

    // Nothing takes the alert: it reached no one, so the next one is not held back.
    $none = ['minimum_severity' => 'info', 'event_types' => []];
    app(SettingsRepositoryService::class)->save(['alert_channels' => ['webhook' => $none, 'slack' => $none, 'telegram' => $none]]);
    $this->subscriber->forceFill(['has_alert_emails' => false])->save();
    $stale();
    app(SettingsRepositoryService::class)->save(['alert_channels' => null]);
    $stale();
    $stale();
    config(['excubia.alert_throttle_minutes' => 0]);
    $stale();

    expect(eventsHistory($project))->toBe(['project.stale:skipped', 'project.stale:queued', 'project.stale:suppressed:'.AlertSuppressionService::THROTTLED, 'project.stale:queued']);
});

it('stops a flapping finding from alerting again within the hour', function () {
    $project = Project::factory()->create();
    storeEventsReport($project, []);
    storeEventsReport($project, [['laravel/framework', 'high']]);
    storeEventsReport($project, []);
    storeEventsReport($project, [['laravel/framework', 'high']]);

    expect(array_values(array_filter(eventsHistory($project), fn (string $alert): bool => str_starts_with($alert, 'finding.'))))->toBe([
        'finding.new:queued',
        'finding.resolved:queued',
        'finding.new:suppressed:throttled',
    ]);
});

it('raises report.checks_skipped for a data source that newly failed, not again while it stays down', function () {
    $project = Project::factory()->create();
    storeEventsReport($project, [], ['composer-advisories']);
    storeEventsReport($project, [], ['composer-advisories']);
    storeEventsReport($project, [], ['composer-advisories', 'npm-advisories']);

    $alerts = AlertLog::query()->where('event', AlertEventEnum::ReportChecksSkipped->value)->orderBy('id')->get();

    expect($alerts->map(fn (AlertLog $alert): array => [$alert->subject, $alert->severity, $alert->summary])->all())->toBe([
        ['composer-advisories', 'low', 'skipped, a data source failed: Composer Advisories'],
        ['npm-advisories', 'low', 'skipped, a data source failed: Npm Advisories'],
    ]);
});

it('raises push.rejected once per burst of token rejections, without keeping the client', function () {
    $system = Project::factory()->create(['is_system' => true]);
    $disabled = Project::factory()->create(['is_enabled' => false]);
    $token = app(RotateProjectTokenAction::class)->execute($disabled);
    clearEventsQueue();

    foreach (range(1, 25) as $attempt) {
        pushWithToken($attempt % 2 === 0 ? null : 'excubia_wrong');
        pushWithToken($token);
    }

    $alerts = AlertLog::query()->where('event', AlertEventEnum::PushRejected->value)->orderBy('id')->get();

    expect($alerts->map(fn (AlertLog $alert): array => [$alert->project_id, $alert->subject, $alert->severity])->all())->toBe([
        [$system->id, 'token', 'high'],
        [$disabled->id, 'disabled', 'high'],
    ])
        ->and(collect(eventsJobs())->first(fn (DeliverAlertJob $job): bool => $job->channel === DeliverAlertJob::WEBHOOK)->payload['data'])
        ->toEqual((object) ['reason' => 'token', 'count' => 10, 'endpoint' => 'reports'])
        ->and(json_encode(AlertLog::query()->get()))->not->toContain('127.0.0.1')
        ->and(IngestRejection::query()->sum('count'))->toEqual(50);

    // A new hour, a new burst.
    Date::setTestNow(Date::now()->addMinutes(61));

    foreach (range(1, 10) as $attempt) {
        pushWithToken($token);
    }

    expect(AlertLog::query()->where('event', AlertEventEnum::PushRejected->value)->count())->toBe(3);
});

it('raises no push.rejected for unknown tokens when there is no system project', function () {
    foreach (range(1, 10) as $attempt) {
        pushWithToken('excubia_wrong');
    }

    expect(AlertLog::query()->count())->toBe(0);
});

it('links project.deleted to the dashboard and raises project.disabled only when a project is turned off', function () {
    $project = Project::factory()->create();
    app(UpdateProjectAction::class)->execute($project, 'Renamed', true);
    app(UpdateProjectAction::class)->execute($project, 'Renamed', false);
    app(UpdateProjectAction::class)->execute($project, 'Renamed again', false);

    expect(eventsHistory($project))->toBe(['project.disabled:queued']);

    app(DeleteProjectAction::class)->execute($project);

    $deleted = collect(eventsJobs())->first(fn (DeliverAlertJob $job): bool => $job->payload['event'] === 'project.deleted');

    expect($deleted->payload['url'])->toBe(route('dashboard'))
        ->and($deleted->payload['project'])->toBe(['name' => 'Renamed again', 'slug' => $project->slug]);
});
