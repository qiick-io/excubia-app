<?php

declare(strict_types=1);

use App\Actions\StorePushedReportAction;
use App\Enums\AlertEventEnum;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Mail\WeeklyDigestMail;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\User;
use App\Services\AlertDispatcherService;
use App\Services\SettingsRepositoryService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Alert controls (#114): mutes of a rule or an issue, maintenance windows and maintenance mode,
 * all applied where every alert passes (AlertDispatcherService); the alert history with its
 * delivery outcomes; the Alerts tab; the optional runtime rules (#107); the weekly digest.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Queue::fake();
    Date::setTestNow('2026-11-10 12:00:00');
    app(SettingsRepositoryService::class)->save([
        // An IP literal: the address check needs no DNS lookup in tests.
        'webhook_url' => 'https://93.184.216.34/excubia',
        'webhook_secret' => str_repeat('s', 32),
    ]);
});

function controlsAdmin(RoleEnum $role = RoleEnum::SuperAdmin): User
{
    return User::factory()->create()->assignRole($role->value);
}

/**
 * Stores a report whose findings all fail, as excubia-core sends it.
 *
 * @param  list<array{0: string, 1: string, 2?: string}>  $findings  [subject, severity, checkId]
 */
function controlsReport(Project $project, array $findings): void
{
    app(StorePushedReportAction::class)->execute($project, (string) Str::uuid(), Date::now(), [
        'schemaVersion' => 1,
        'checks' => [[
            'checkId' => 'composer-audit',
            'name' => 'Composer audit',
            'status' => $findings === [] ? 'pass' : 'fail',
            'findings' => array_map(fn (array $finding): array => [
                'checkId' => $finding[2] ?? 'composer-audit', 'severity' => $finding[1], 'status' => 'fail', 'subject' => $finding[0],
                'message' => $finding[0] === 'maintenance-mode' ? 'The application is in maintenance mode.' : 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null,
            ], $findings),
        ]],
        'upgradePlan' => [],
    ]);
}

/**
 * @return list<string> "event:status:reason" of the project's alert history, oldest first
 */
function alertHistory(Project $project): array
{
    return AlertLog::query()->where('project_id', $project->id)->orderBy('id')->get()
        ->map(fn (AlertLog $log): string => "{$log->event}:{$log->status}:".($log->reason ?? '-'))
        ->all();
}

it('logs every alert and suppresses a muted rule until the mute ends', function () {
    $project = Project::factory()->create();
    $alerts = app(AlertDispatcherService::class);

    $alerts->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'project.stale', 'until' => Date::now()->addHour()]);
    $alerts->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');
    Date::setTestNow(Date::now()->addHours(2));
    $alerts->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');

    expect(alertHistory($project))->toBe(['project.stale:queued:-', 'project.stale:suppressed:muted', 'project.stale:queued:-']);
    Queue::assertPushed(DeliverAlertJob::class, 2);
});

it('mutes one issue without muting the rest of the rule', function () {
    $project = Project::factory()->create();
    controlsReport($project, []);
    controlsReport($project, [['first/package', 'high']]);
    $first = AlertLog::query()->where('event', 'finding.new')->sole();

    $this->actingAs(controlsAdmin())
        ->post(route('projects.alert-mutes.store', $project), ['alert_id' => $first->id, 'scope' => 'issue', 'duration' => 'forever'])
        ->assertRedirect();

    controlsReport($project, []);
    controlsReport($project, [['first/package', 'high'], ['second/package', 'high']]);

    expect(AlertMute::query()->sole())->subject->toBe('composer-audit|first/package|')->until->toBeNull()
        ->and(AlertLog::query()->where('event', 'finding.new')->orderBy('id')->get()->map(fn (AlertLog $log): string => "{$log->summary}:{$log->status}")->all())
        ->toBe(['first/package: Known vulnerability:queued', 'first/package: Known vulnerability:suppressed', 'second/package: Known vulnerability:queued']);
});

it('snoozes a rule for 1 h, 24 h or 7 d and unmutes it', function (string $duration, int $hours) {
    $project = Project::factory()->create();
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');
    $admin = controlsAdmin();

    $this->actingAs($admin)
        ->post(route('projects.alert-mutes.store', $project), ['alert_id' => AlertLog::query()->value('id'), 'scope' => 'rule', 'duration' => $duration])
        ->assertInertiaFlash('toast.type', 'success');

    $mute = AlertMute::query()->sole();
    expect($mute->subject)->toBeNull()
        ->and($mute->until?->equalTo(Date::now()->addHours($hours)))->toBeTrue()
        ->and($mute->user_id)->toBe($admin->id);

    $this->actingAs($admin)->delete(route('projects.alert-mutes.destroy', [$project, $mute]))->assertRedirect();
    expect(AlertMute::query()->count())->toBe(0);
})->with([['1h', 1], ['24h', 24], ['7d', 168]]);

it('refuses alert controls to users who cannot edit the project, and foreign rows', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $other, 'no report');
    $foreignMute = AlertMute::query()->create(['project_id' => $other->id, 'event' => 'project.stale']);
    $reader = controlsAdmin(RoleEnum::Reader);

    $this->actingAs($reader)
        ->post(route('projects.maintenance-windows.store', $project), ['name' => 'x', 'recurrence' => 'weekly', 'timezone' => 'UTC', 'weekdays' => [1], 'start_time' => '02:00', 'duration_minutes' => 30])
        ->assertForbidden();
    $this->actingAs($reader)->delete(route('projects.alert-mutes.destroy', [$other, $foreignMute]))->assertForbidden();

    $admin = controlsAdmin();
    $this->actingAs($admin)->delete(route('projects.alert-mutes.destroy', [$project, $foreignMute]))->assertNotFound();
    // The alert belongs to another project, and a rule-level alert has no issue to mute.
    $this->actingAs($admin)
        ->post(route('projects.alert-mutes.store', $project), ['alert_id' => AlertLog::query()->value('id'), 'scope' => 'rule', 'duration' => '1h'])
        ->assertSessionHasErrors('alert_id');
    $this->actingAs($admin)
        ->post(route('projects.alert-mutes.store', $other), ['alert_id' => AlertLog::query()->value('id'), 'scope' => 'issue', 'duration' => '1h'])
        ->assertStatus(422);
});

it('stores maintenance windows in UTC and suppresses alerts while one is in force', function () {
    $project = Project::factory()->create();
    $admin = controlsAdmin();

    $this->actingAs($admin)
        ->post(route('projects.maintenance-windows.store', $project), ['name' => 'Upgrade', 'recurrence' => 'once', 'timezone' => 'Europe/Rome', 'starts_at' => '2026-11-10 13:00', 'ends_at' => '2026-11-10 12:00'])
        ->assertSessionHasErrors(['starts_at', 'ends_at']);

    // 12:30–14:00 in Rome is 11:30–13:00 UTC: in force at 12:00 UTC.
    $this->actingAs($admin)
        ->post(route('projects.maintenance-windows.store', $project), ['name' => 'Upgrade', 'recurrence' => 'once', 'timezone' => 'Europe/Rome', 'starts_at' => '2026-11-10T12:30', 'ends_at' => '2026-11-10T14:00'])
        ->assertSessionHasNoErrors();

    $window = MaintenanceWindow::query()->sole();
    expect($window->starts_at?->toIso8601ZuluString())->toBe('2026-11-10T11:30:00Z');

    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');
    // A token change is a security event: maintenance does not hide it.
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectTokenChanged, $project, 'push token rotated');

    expect(alertHistory($project))->toBe(['project.stale:suppressed:maintenance_window', 'project.token_changed:queued:-']);

    $this->actingAs($admin)->delete(route('projects.maintenance-windows.destroy', [$project, $window]))->assertRedirect();
    expect(MaintenanceWindow::query()->count())->toBe(0);
});

it('validates weekly maintenance windows', function () {
    $project = Project::factory()->create();

    $this->actingAs(controlsAdmin())
        ->post(route('projects.maintenance-windows.store', $project), ['name' => 'Nightly', 'recurrence' => 'weekly', 'timezone' => 'Mars/Olympus', 'weekdays' => [8], 'start_time' => '25:00', 'duration_minutes' => 20000])
        ->assertSessionHasErrors(['timezone', 'weekdays.0', 'start_time', 'duration_minutes']);

    $this->actingAs(controlsAdmin())
        ->post(route('projects.maintenance-windows.store', $project), ['name' => 'Nightly', 'recurrence' => 'weekly', 'timezone' => 'Asia/Tokyo', 'weekdays' => [1, 2], 'start_time' => '23:00', 'duration_minutes' => 120])
        ->assertSessionHasNoErrors();

    expect(MaintenanceWindow::query()->sole())
        ->weekdays->toBe([1, 2])->start_time->toBe('23:00')->starts_at->toBeNull();
});

it('honours the core maintenance mode finding, except for schedule and token events', function () {
    $project = Project::factory()->create();
    controlsReport($project, []);
    controlsReport($project, [['maintenance-mode', 'medium', 'laravel-maintenance-mode'], ['some/package', 'high']]);
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ScheduleFailed, $project, 'artisan reports:send: failed', subject: 'task');

    expect(alertHistory($project))->toBe([
        'finding.new:suppressed:maintenance_mode',
        'finding.new:suppressed:maintenance_mode',
        'project.worsened:suppressed:maintenance_mode',
        'schedule.failed:queued:-',
    ]);
});

it('records each channel delivery on the alert history', function () {
    $project = Project::factory()->create();
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');
    [$delivered, $failed] = collect(Queue::pushedJobs()[DeliverAlertJob::class])->map(fn (array $job) => $job['job'])->all();

    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);
    $delivered->handle(app(SettingsRepositoryService::class));
    $failed->failed(new RuntimeException('HTTP 500'));

    expect(AlertLog::query()->orderBy('id')->get()->map(fn (AlertLog $log): array => [$log->status, $log->deliveries])->all())
        ->toBe([['sent', ['webhook' => 'sent']], ['failed', ['webhook' => 'failed']]]);
});

it('skips an alert no channel takes', function () {
    app(SettingsRepositoryService::class)->save(['webhook_url' => null]);
    $project = Project::factory()->create();

    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report');

    expect(alertHistory($project))->toBe(['project.stale:skipped:-']);
});

it('shows the history, mutes and windows on the Alerts tab', function () {
    $project = Project::factory()->create();
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ScheduleFailed, $project, 'artisan reports:send: failed', subject: 'task-1');
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'schedule.failed', 'subject' => 'task-1', 'label' => 'artisan reports:send']);
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'project.stale', 'until' => Date::now()->subMinute()]);
    MaintenanceWindow::query()->create(['project_id' => $project->id, 'name' => 'Nightly', 'recurrence' => 'weekly', 'weekdays' => [2], 'start_time' => '12:00', 'duration_minutes' => 60, 'timezone' => 'UTC']);

    $this->actingAs(controlsAdmin())->get(route('projects.view', [$project->slug, 'alerts']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('view', 'alerts')
            ->where('canManageAlerts', true)
            ->where('alerts.history.0.event', 'schedule.failed')
            ->where('alerts.history.0.status', 'queued')
            ->where('alerts.history.0.has_subject', true)
            ->where('alerts.history.0.deliveries.webhook', 'queued')
            ->has('alerts.mutes', 1)
            ->where('alerts.mutes.0.is_issue', true)
            ->where('alerts.windows.0.is_active', true)
            ->where('alerts.isInMaintenanceMode', false)
            ->missing('alerts.history.0.subject'));

    // The data stays off the other tabs, and readers cannot manage alerts.
    $this->actingAs(controlsAdmin(RoleEnum::Reader))->get(route('projects.show', $project->slug))
        ->assertInertia(fn (Assert $page) => $page->where('alerts', null)->where('canManageAlerts', false));
});

it('saves the runtime alert rules in Settings → Integrations', function () {
    $this->actingAs(controlsAdmin())->put(route('project.integrations.update'), [
        'notifier_minimum_severity' => 'high',
        'alert_cache_hit_ratio_below' => '80',
        'alert_delivery_failures_enabled' => '1',
        'alert_log_spike_per_hour' => '',
    ])->assertSessionHasNoErrors();

    $settings = app(SettingsRepositoryService::class);
    expect($settings->alertCacheHitRatioBelow())->toBe(80)
        ->and($settings->shouldAlertDeliveryFailures())->toBeTrue()
        ->and($settings->alertLogSpikePerHour())->toBeNull();

    $this->actingAs(controlsAdmin())->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.alert_cache_hit_ratio_below', 80)
            ->where('events', fn ($events): bool => collect($events)->contains('runtime.log_spike')));

    $this->actingAs(controlsAdmin())->put(route('project.integrations.update'), ['notifier_minimum_severity' => 'high', 'alert_cache_hit_ratio_below' => '150'])
        ->assertSessionHasErrors('alert_cache_hit_ratio_below');
});

/**
 * @param  array<string, string>  $dimensions
 * @param  array<string, int>  $metrics
 */
function alertRuntimeRow(Project $project, string $type, array $dimensions, int $count, int $errors = 0, array $metrics = []): void
{
    RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => $type,
        'bucket' => Date::now()->subMinutes(10)->startOfMinute(),
        'sample_rate' => 1,
        'dimension_key' => hash('sha256', $type.json_encode($dimensions)),
        'dimensions' => $dimensions,
        'count' => $count,
        'error_count' => $errors,
        'metrics' => $metrics === [] ? null : $metrics,
    ]);
}

it('raises the runtime alert rules once per hour and subject, only when they are on', function () {
    $project = Project::factory()->create();
    alertRuntimeRow($project, 'cache', ['store' => 'redis', 'keyPrefix' => 'app'], 0, metrics: ['hits' => 50, 'misses' => 150]);
    alertRuntimeRow($project, 'cache', ['store' => 'array', 'keyPrefix' => 'app'], 0, metrics: ['hits' => 5, 'misses' => 5]);
    alertRuntimeRow($project, 'mail', ['mailable' => 'App\\Mail\\Invoice', 'mailer' => 'smtp'], 3, 2);
    alertRuntimeRow($project, 'notification', ['notification' => 'App\\Notifications\\Welcome', 'channel' => 'mail'], 4, 0);
    alertRuntimeRow($project, 'logLevel', ['level' => 'error', 'channel' => 'payments'], 60);
    alertRuntimeRow($project, 'logLevel', ['level' => 'warning', 'channel' => 'payments'], 500);
    alertRuntimeRow($project, 'logLevel', ['level' => 'error', 'channel' => 'stack'], 3);

    $this->artisan('excubia:evaluate-runtime-alerts')->expectsOutputToContain('No runtime alert rule is on')->assertSuccessful();
    expect(AlertLog::query()->count())->toBe(0);

    app(SettingsRepositoryService::class)->save(['alert_cache_hit_ratio_below' => 80, 'alert_delivery_failures_enabled' => true, 'alert_log_spike_per_hour' => 50]);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->orderBy('id')->get()->map(fn (AlertLog $log): string => "{$log->event}|{$log->subject}")->all())->toBe([
        'runtime.cache_hit_ratio_low|cache:redis',
        'runtime.delivery_failed|mail:App\\Mail\\Invoice',
        'runtime.log_spike|log:payments',
    ]);

    Date::setTestNow(Date::now()->addMinutes(61));
    alertRuntimeRow($project, 'logLevel', ['level' => 'critical', 'channel' => 'payments'], 70);
    $this->artisan('excubia:evaluate-runtime-alerts')->assertSuccessful();

    expect(AlertLog::query()->where('event', 'runtime.log_spike')->count())->toBe(2);
});

it('saves the weekly digest choice on the profile', function () {
    $user = controlsAdmin();

    $this->actingAs($user)->patch(route('profile.update'), [
        'first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->email, 'has_weekly_digest' => '1',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->has_weekly_digest)->toBeTrue();
});

it('mails the weekly digest to the users who want it, in their locale', function () {
    Mail::fake();
    $project = Project::factory()->create(['name' => 'Shop']);
    Project::factory()->create(['name' => 'Quiet']);
    Date::setTestNow('2026-11-01 12:00:00');
    controlsReport($project, [['old/package', 'low']]);
    Date::setTestNow('2026-11-09 12:00:00');
    controlsReport($project, [['old/package', 'low'], ['new/package', 'critical'], ['8.2', 'high', 'php-eol']]);
    ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat('a', 64), 'environment' => 'production', 'class' => 'RuntimeException',
        'message' => 'secret body', 'frames' => [], 'previous' => [], 'context_kind' => 'request', 'context_name' => 'checkout.store',
        'count' => 12, 'first_seen' => Date::now()->subDays(20), 'last_seen' => Date::now(), 'status' => 'open',
    ]);
    $project->scheduledTasks()->create([
        'task_id' => str_repeat('b', 64), 'task' => 'artisan reports:send', 'expression' => '0 3 * * *', 'timezone' => 'UTC', 'environments' => [],
    ])->forceFill(['status' => 'missed'])->save();
    Date::setTestNow('2026-11-10 08:00:00');

    $reader = controlsAdmin(RoleEnum::Reader);
    $reader->forceFill(['has_weekly_digest' => true, 'locale' => 'it'])->save();
    controlsAdmin()->forceFill(['has_weekly_digest' => false])->save();

    $this->artisan('excubia:send-digests')->assertSuccessful();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use ($reader): bool {
        $html = $mail->locale('it')->render();
        [$shop] = $mail->projects;

        return $mail->hasTo($reader->email)
            && count($mail->projects) === 1
            && $shop['name'] === 'Shop'
            && $shop['newFindingsCount'] === 2
            && $shop['regressions'] === ['worst severity is now critical']
            && $shop['eol'] === ['Known vulnerability']
            && $shop['exceptions'] === [['class' => 'RuntimeException', 'where' => 'checkout.store', 'count' => 12]]
            && $shop['schedule'] === [['task' => 'artisan reports:send', 'status' => 'missed']]
            && str_contains($html, 'Nuovi problemi')
            && str_contains($html, 'Saltato')
            && ! str_contains($html, 'secret body');
    });
});

it('sends no digest when nothing happened', function () {
    Mail::fake();
    controlsAdmin()->forceFill(['has_weekly_digest' => true])->save();
    Project::factory()->create();

    $this->artisan('excubia:send-digests')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('prunes old alert history and ended mutes', function () {
    $project = Project::factory()->create();
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'old');
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'project.stale', 'until' => Date::now()->addHour()]);
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'finding.new']);
    Date::setTestNow(Date::now()->addDays(400));
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'new');

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect(AlertLog::query()->pluck('summary')->all())->toBe(['new'])
        ->and(AlertMute::query()->pluck('event')->all())->toBe(['finding.new']);
});
