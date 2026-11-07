<?php

declare(strict_types=1);

use App\Enums\AlertEventEnum;
use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Jobs\DeliverAlertJob;
use App\Mail\AlertMail;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\MaintenanceWindow;
use App\Models\Project;
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
use Spatie\Permission\Models\Role;

/**
 * Alerts (#22), second part: the email channel with per-user subscriptions, the per-channel
 * filters (minimum severity, events, projects), the admin's default severity per event and the
 * `can-manage-alerts` permission.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-07 12:00:00');
});

function emailUser(RoleEnum $role = RoleEnum::Reader, array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole($role->value);
}

/**
 * @param  array<string, mixed>  $filter
 */
function subscribe(User $user, array $filter = []): User
{
    $user->forceFill(['has_alert_emails' => true, 'alert_email_filters' => [
        'minimum_severity' => 'info', 'event_types' => null, 'project_ids' => [], ...$filter,
    ]])->save();

    return $user;
}

/**
 * The channels queued for the alerts dispatched so far.
 *
 * @return list<string>
 */
function queuedChannels(): array
{
    return collect(Queue::pushedJobs()[DeliverAlertJob::class] ?? [])
        ->map(fn (array $job): string => $job['job']->channel)
        ->values()->all();
}

function staleAlert(Project $project): void
{
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report since yesterday');
}

it('gives can-manage-alerts to admins and super-admins, and gates Integrations with it', function () {
    expect(emailUser(RoleEnum::Admin)->can(PermissionEnum::CanManageAlerts->value))->toBeTrue()
        ->and(emailUser(RoleEnum::Reader)->can(PermissionEnum::CanManageAlerts->value))->toBeFalse();

    $this->actingAs(emailUser(RoleEnum::Admin))->get(route('project.integrations.edit'))->assertOk();
    $this->actingAs(emailUser(RoleEnum::Reader))->get(route('project.integrations.edit'))->assertForbidden();

    // The permission alone opens Integrations, not the other project settings.
    Role::create(['name' => 'alert-keeper'])->givePermissionTo([PermissionEnum::CanShowProjects->value, PermissionEnum::CanManageAlerts->value]);
    $keeper = User::factory()->create()->assignRole('alert-keeper');

    $this->actingAs($keeper)->get(route('project.integrations.edit'))->assertOk();
    $this->actingAs($keeper)->get(route('project.edit'))->assertForbidden();
    $this->actingAs($keeper)->put(route('project.integrations.update'), [])->assertSessionHasErrors('channels.webhook.minimum_severity');
});

it('needs can-manage-alerts, not the right to edit the project, to mute and add maintenance windows', function () {
    $project = Project::factory()->create();
    $alert = AlertLog::query()->create([
        'uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'event' => 'project.stale', 'severity' => 'medium',
        'summary' => 'stale', 'status' => AlertLog::SKIPPED, 'deliveries' => [],
    ]);
    Role::create(['name' => 'project-editor'])->givePermissionTo([PermissionEnum::CanShowProjects->value, PermissionEnum::CanEditProjects->value]);
    $editor = User::factory()->create()->assignRole('project-editor');
    $window = ['name' => 'Deploy', 'recurrence' => 'once', 'timezone' => 'UTC', 'starts_at' => '2026-11-08T10:00', 'ends_at' => '2026-11-08T11:00'];

    $this->actingAs($editor)->post(route('projects.alert-mutes.store', $project), ['alert_id' => $alert->id, 'scope' => 'rule', 'duration' => '1h'])->assertForbidden();
    $this->actingAs($editor)->post(route('projects.maintenance-windows.store', $project), $window)->assertForbidden();
    $this->actingAs($editor)->get(route('projects.show', $project->slug))
        ->assertInertia(fn (Assert $page) => $page->where('canManageAlerts', false));

    $admin = emailUser(RoleEnum::Admin);
    $this->actingAs($admin)->post(route('projects.alert-mutes.store', $project), ['alert_id' => $alert->id, 'scope' => 'rule', 'duration' => '1h'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('projects.maintenance-windows.store', $project), $window)->assertSessionHasNoErrors();
    $this->actingAs($admin)->get(route('projects.show', $project->slug))
        ->assertInertia(fn (Assert $page) => $page->where('canManageAlerts', true));

    expect(AlertMute::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(MaintenanceWindow::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('saves the per-channel filters and the default severities, and applies them', function () {
    Queue::fake();
    $admin = emailUser(RoleEnum::Admin);
    [$shop, $blog] = [Project::factory()->create(['name' => 'Shop']), Project::factory()->create(['name' => 'Blog'])];

    $this->actingAs($admin)->put(route('project.integrations.update'), [
        'notifier_slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'notifier_telegram_bot_token' => '123456:ABC-def_1',
        'notifier_telegram_chat_id' => '-1001234567890',
        'channels' => [
            'webhook' => ['minimum_severity' => 'info', 'event_types' => ['project.stale']],
            'slack' => ['minimum_severity' => 'critical', 'event_types' => ['project.stale']],
            'telegram' => ['minimum_severity' => 'medium', 'event_types' => ['project.stale'], 'project_ids' => [$shop->id]],
        ],
        'event_severities' => ['project.stale' => 'critical'],
    ])->assertSessionHasNoErrors();

    $settings = app(SettingsRepositoryService::class);
    expect($settings->alertEventSeverity(AlertEventEnum::ProjectStale)->value)->toBe('critical')
        ->and($settings->alertEventSeverity(AlertEventEnum::ProjectRecovered)->value)->toBe('info')
        ->and($settings->alertChannelFilter('telegram')->projectIds)->toBe([$shop->id]);

    $this->actingAs($admin)->get(route('project.integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.event_severities', fn ($severities): bool => $severities['project.stale'] === 'critical' && ! isset($severities['finding.new']))
            ->where('project.channels.telegram.project_ids', [$shop->id])
            ->has('projects', 2));

    // project.stale is now critical, so it reaches Slack (critical only); Telegram only for Shop.
    staleAlert($shop);
    staleAlert($blog);

    expect(queuedChannels())->toBe(['slack', 'telegram', 'slack'])
        ->and(AlertLog::query()->where('project_id', $shop->id)->sole()->severity)->toBe('critical');

    // finding.new takes the finding's severity, so it cannot be overridden; unknown severities fail.
    $this->actingAs($admin)->put(route('project.integrations.update'), [
        'channels' => ['webhook' => ['minimum_severity' => 'info'], 'slack' => ['minimum_severity' => 'high'], 'telegram' => ['minimum_severity' => 'high']],
        'event_severities' => ['finding.new' => 'low', 'project.stale' => 'loud'],
    ])->assertSessionHasErrors('event_severities.project.stale');

    $this->actingAs($admin)->put(route('project.integrations.update'), [
        'channels' => ['webhook' => ['minimum_severity' => 'info'], 'slack' => ['minimum_severity' => 'high'], 'telegram' => ['minimum_severity' => 'high']],
        'event_severities' => ['finding.new' => 'low', 'project.stale' => 'low'],
    ])->assertSessionHasNoErrors();

    expect(app(SettingsRepositoryService::class)->all()['alert_event_severities'])->toBe(['project.stale' => 'low']);
});

it('lets a user subscribe to alert emails with their own filters, limited to the projects they can see', function () {
    $reader = emailUser(RoleEnum::Reader);
    $public = Project::factory()->create(['name' => 'Public']);
    $hidden = Project::factory()->create(['name' => 'Hidden', 'visibility' => ProjectVisibilityEnum::Admins]);

    $this->actingAs($reader)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('alertSubscription.has_alert_emails', false)
            ->where('alertSubscription.filter.minimum_severity', 'high')
            ->where('alertSubscription.projects', [['id' => $public->id, 'name' => 'Public']]));

    $this->actingAs($reader)->put(route('profile.alerts.update'), [
        'has_alert_emails' => '1',
        'alerts' => ['minimum_severity' => 'medium', 'event_types' => ['project.stale'], 'project_ids' => [$hidden->id]],
    ])->assertSessionHasErrors('alerts.project_ids.0');

    $this->actingAs($reader)->put(route('profile.alerts.update'), [
        'has_alert_emails' => '1',
        'alerts' => ['minimum_severity' => 'medium', 'event_types' => ['project.stale'], 'project_ids' => [$public->id]],
    ])->assertRedirect(route('profile.edit'));

    expect($reader->fresh()->has_alert_emails)->toBeTrue()
        ->and($reader->fresh()->alert_email_filters)->toBe(['minimum_severity' => 'medium', 'event_types' => ['project.stale'], 'project_ids' => [$public->id]]);

    // Users who cannot see projects have no subscription.
    Role::create(['name' => 'nobody']);
    $nobody = User::factory()->create()->assignRole('nobody');
    $this->actingAs($nobody)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('alertSubscription', null));
    $this->actingAs($nobody)->put(route('profile.alerts.update'), ['alerts' => ['minimum_severity' => 'high']])->assertForbidden();
});

it('queues one email per subscriber whose filters take the alert and who can see the project', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);

    $everything = subscribe(emailUser());
    $admin = subscribe(emailUser(RoleEnum::Admin));
    subscribe(emailUser(), ['minimum_severity' => 'high']);                       // project.stale is medium
    subscribe(emailUser(), ['event_types' => ['finding.new']]);                   // other events
    subscribe(emailUser(), ['project_ids' => [$hidden->id]]);                     // other projects (and cannot see it)
    subscribe(emailUser(attributes: ['is_active' => false]));                     // disabled
    subscribe(emailUser(attributes: ['email_verified_at' => null]));              // unverified
    emailUser();                                                                  // not subscribed

    staleAlert($project);
    expect(queuedChannels())->toBe(['email:'.$everything->id, 'email:'.$admin->id]);

    // Only the admin can see an admins-only project.
    Queue::fake();
    staleAlert($hidden);
    expect(queuedChannels())->toBe(['email:'.$admin->id]);
});

it('mails the alert in the user\'s locale and records the delivery per user', function () {
    Mail::fake();
    $project = Project::factory()->create(['name' => 'Shop']);
    $italian = subscribe(emailUser(attributes: ['locale' => 'it']));
    $german = subscribe(emailUser(attributes: ['locale' => 'de']));

    staleAlert($project);

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo($italian->email) && $mail->locale === 'it');
    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo($german->email) && $mail->locale === 'de'
        && $mail->envelope()->subject === '[MEDIUM] project.stale · Shop');
    Mail::assertSentCount(2);

    $log = AlertLog::query()->sole();
    expect($log->status)->toBe(AlertLog::SENT)
        ->and($log->deliveries)->toBe(['email:'.$italian->id => AlertLog::SENT, 'email:'.$german->id => AlertLog::SENT]);

    // The rendered mail is translated and carries no user data.
    app()->setLocale('it');
    $html = (new AlertMail(['event' => 'project.stale', 'severity' => 'medium', 'project' => ['name' => 'Shop', 'slug' => 'shop'], 'url' => 'https://excubia.test/projects/shop'], 'no report since yesterday'))->render();
    expect($html)->toContain('Gravità')->toContain('Media')->toContain('no report since yesterday')->not->toContain($italian->email);

    // The Alerts tab folds the mails into one "email" entry, without user ids.
    $this->actingAs(emailUser(RoleEnum::Admin))->get(route('projects.view', [$project->slug, 'alerts']))
        ->assertInertia(fn (Assert $page) => $page->where('alerts.history.0.deliveries', ['email' => AlertLog::SENT]));
});

it('records a failed mail and a mail to a user disabled since the alert', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $user = subscribe(emailUser());
    staleAlert($project);
    $job = Queue::pushedJobs()[DeliverAlertJob::class][0]['job'];

    $job->failed(new RuntimeException('SMTP down'));
    expect(AlertLog::query()->sole()->status)->toBe(AlertLog::FAILED);

    Mail::fake();
    $user->forceFill(['is_active' => false])->save();
    $job->handle(app(SettingsRepositoryService::class));

    Mail::assertNothingSent();
    expect(AlertLog::query()->sole()->deliveries)->toBe(['email:'.$user->id => AlertLog::FAILED]);
});

it('queues a test email to the signed-in user', function () {
    Queue::fake();
    $user = emailUser();

    $this->actingAs($user)->post(route('profile.alerts.test'))->assertInertiaFlash('toast.type', 'success');

    Queue::assertPushed(DeliverAlertJob::class, fn (DeliverAlertJob $job): bool => $job->channel === 'email:'.$user->id && $job->payload['event'] === 'ping');
});
