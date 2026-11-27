<?php

declare(strict_types=1);

use App\Actions\PruneTelemetryAction;
use App\Enums\AlertEventEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\AlertLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\AlertDispatcherService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * In-app alerts (#296): the `app:<user id>` alert channel stores a database notification per
 * user whose filters take the alert and who can see the project, grouped per report push and
 * pruned after 30 days; and the notification centre's endpoints and shared badge prop.
 *
 * The queue runs synchronously in tests, so each dispatched alert is stored at once.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-12 12:00:00');
});

function inAppUser(RoleEnum $role = RoleEnum::Reader, array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole($role->value);
}

/**
 * A high finding.new alert about one package, so alerts about different packages are not
 * held back as duplicates of each other. Pass the dispatcher that groups the alerts.
 */
function highAlert(Project $project, string $package = 'laravel/framework', ?AlertDispatcherService $dispatcher = null): void
{
    ($dispatcher ?? app(AlertDispatcherService::class))->dispatch(AlertEventEnum::FindingNew, $project, "{$package}: CVE-2026-0001", [
        'checkId' => 'composer-audit', 'subject' => $package, 'advisoryId' => 'CVE-2026-0001', 'severity' => 'high',
    ], SeverityEnum::High, "composer-audit|{$package}|CVE-2026-0001");
}

/**
 * @return array<int, int> notifications stored per user id
 */
function notificationsPerUser(): array
{
    return DatabaseNotification::query()->get()->countBy('notifiable_id')->all();
}

it('stores one notification per user whose filters take the alert and who can see the project', function () {
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    $hidden = Project::factory()->create(['visibility' => ProjectVisibilityEnum::Admins]);

    $byDefault = inAppUser();                                                         // in-app on, from high
    $admin = inAppUser(RoleEnum::Admin);
    $turnedOff = inAppUser(attributes: ['has_alert_in_app' => false]);
    $otherEvents = inAppUser()->forceFill(['alert_email_filters' => ['minimum_severity' => 'info', 'event_types' => ['project.stale']]]);
    $otherEvents->save();
    $otherProjects = inAppUser()->forceFill(['alert_email_filters' => ['minimum_severity' => 'info', 'project_ids' => [$hidden->id]]]);
    $otherProjects->save();
    inAppUser(attributes: ['is_active' => false]);
    // An unverified address stops the mail, not the in-app notification.
    $unverified = inAppUser(attributes: ['email_verified_at' => null]);

    highAlert($project);

    expect(notificationsPerUser())->toEqual([$byDefault->id => 1, $admin->id => 1, $unverified->id => 1]);

    $notification = $byDefault->notifications()->sole();
    expect($notification->type)->toBe(AlertNotification::class)
        ->and($notification->data)->toMatchArray([
            'type' => 'alert',
            'event' => 'finding.new',
            'severity' => 'high',
            'project' => ['name' => 'Billing API', 'slug' => 'billing-api'],
            'count' => 1,
            'events' => ['finding.new'],
            'title' => '[HIGH] finding.new · Billing API',
            'body' => 'laravel/framework: CVE-2026-0001',
            // The finding itself, as the Findings tab opens it.
            'url' => '/projects/billing-api/findings?finding='.rawurlencode('composer-audit|laravel/framework|CVE-2026-0001'),
        ]);

    // The delivery is recorded per user and folded into one "app" entry on the Alerts tab.
    $log = AlertLog::query()->sole();
    expect($log->status)->toBe(AlertLog::SENT)
        ->and($log->deliveries)->toEqual(['app:'.$byDefault->id => AlertLog::SENT, 'app:'.$admin->id => AlertLog::SENT, 'app:'.$unverified->id => AlertLog::SENT]);
    $this->actingAs($admin)->get(route('projects.view', [$project->slug, 'alerts']))
        ->assertInertia(fn (Assert $page) => $page->where('alerts.history.0.deliveries', ['app' => AlertLog::SENT]));

    // The admin-only project reaches only the admin; a medium alert is under the default minimum.
    highAlert($hidden);
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ProjectStale, $project, 'no report since yesterday');

    // The user who picked project.stale from info gets it.
    expect(notificationsPerUser())->toEqual([$byDefault->id => 1, $admin->id => 2, $unverified->id => 1, $otherEvents->id => 1]);
});

it('stores one notification for every alert of a report push, linked to the findings', function () {
    $project = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    $user = inAppUser();
    $dispatcher = app(AlertDispatcherService::class);

    $dispatcher->grouped(function () use ($dispatcher, $project): void {
        foreach (range(1, 30) as $number) {
            highAlert($project, "vendor/package-{$number}", $dispatcher);
        }
    });

    expect(AlertLog::query()->count())->toBe(30)
        ->and($user->notifications()->count())->toBe(1);

    expect($user->notifications()->sole()->data)->toMatchArray([
        'count' => 30,
        'events' => ['finding.new'],
        'title' => '[HIGH] 30 alerts · Billing API',
        'url' => '/projects/billing-api/findings',
    ]);

    // Mixed events link to the project overview; a deleted project to the dashboard.
    $dispatcher->grouped(function () use ($dispatcher, $project): void {
        highAlert($project, 'vendor/another', $dispatcher);
        $dispatcher->dispatch(AlertEventEnum::ProjectWorsened, $project, 'worst severity is now critical', [], SeverityEnum::Critical);
    });
    $dispatcher->dispatch(AlertEventEnum::ProjectDeleted, $project, 'project deleted', [], SeverityEnum::High);

    expect($user->notifications()->latest()->get()->map(fn (DatabaseNotification $notification): string => $notification->data['url'])->sort()->values()->all())
        ->toBe(['/dashboard', '/projects/billing-api', '/projects/billing-api/findings']);
});

it('prunes notifications older than 30 days, read or not', function () {
    $user = inAppUser();
    $store = fn (int $daysAgo, bool $isRead) => $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => AlertNotification::class, 'data' => ['title' => "{$daysAgo} days"],
        'read_at' => $isRead ? Date::now() : null, 'created_at' => Date::now()->subDays($daysAgo),
    ]);
    $store(31, true);
    $store(31, false);
    $kept = $store(29, false);

    app(PruneTelemetryAction::class)->execute(30, 30);

    expect(DatabaseNotification::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('lists, counts and marks only the signed-in user\'s notifications', function () {
    $user = inAppUser();
    $other = inAppUser();
    $project = Project::factory()->create();
    highAlert($project, 'vendor/first');
    Date::setTestNow('2026-11-12 12:05:00');
    highAlert($project, 'vendor/second');
    [$newest, $oldest] = $user->notifications()->get()->all();
    $othersNotification = $other->notifications()->first();

    $this->actingAs($user)->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.0.data.body', 'vendor/second: CVE-2026-0001')
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.0.created_at', '2026-11-12T12:05:00+00:00');
    $this->actingAs($user)->getJson(route('notifications.unread-count'))->assertExactJson(['count' => 2]);

    // Another user's id changes nothing.
    $this->actingAs($user)->postJson(route('notifications.read'), ['ids' => [$othersNotification->id, $oldest->id]])
        ->assertExactJson(['unread_count' => 1]);
    expect($othersNotification->fresh()->read_at)->toBeNull();

    $this->actingAs($user)->postJson(route('notifications.read'), ['all' => true])->assertExactJson(['unread_count' => 0]);
    $this->actingAs($user)->postJson(route('notifications.unread'), ['ids' => [$newest->id, $othersNotification->id]])
        ->assertExactJson(['unread_count' => 1]);
    expect($other->unreadNotifications()->count())->toBe(2);

    $this->actingAs($user)->postJson(route('notifications.read'), ['ids' => ['not-a-uuid']])->assertJsonValidationErrors('ids.0');
    $this->actingAs($user)->postJson(route('notifications.read'), [])->assertJsonValidationErrors('ids');
    $this->actingAs($user)->postJson(route('notifications.unread'), [])->assertJsonValidationErrors('ids');
    auth()->logout();
    $this->getJson(route('notifications.index'))->assertUnauthorized();
});

it('shares the unread count on every page, so the badge updates on navigation without broadcasting', function () {
    $user = inAppUser();
    $project = Project::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread_count', 0));

    highAlert($project);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread_count', 1));
});

it('lets a user turn their in-app alerts off and on next to the alert emails', function () {
    $user = inAppUser();
    $project = Project::factory()->create();
    $filter = ['minimum_severity' => 'high', 'event_types' => ['finding.new'], 'project_ids' => []];

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('alertSubscription.has_alert_in_app', true));

    $this->actingAs($user)->put(route('profile.alerts.update'), ['has_alert_emails' => '0', 'has_alert_in_app' => '0', 'alerts' => $filter])
        ->assertSessionHasNoErrors();
    highAlert($project);
    expect($user->notifications()->count())->toBe(0);

    // A save without the field (an older page) keeps the current choice.
    $this->actingAs($user)->put(route('profile.alerts.update'), ['has_alert_emails' => '0', 'alerts' => $filter])->assertSessionHasNoErrors();
    expect($user->fresh()->has_alert_in_app)->toBeFalse();

    $this->actingAs($user)->put(route('profile.alerts.update'), ['has_alert_emails' => '0', 'has_alert_in_app' => '1', 'alerts' => $filter])
        ->assertSessionHasNoErrors();
    highAlert($project, 'vendor/again');
    expect($user->notifications()->count())->toBe(1);
});
