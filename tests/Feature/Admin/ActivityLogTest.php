<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Activity log copied from externa-core: what admins change is logged with them as causer,
 * and the `/activity-logs` page sits behind `activity.view`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function activityActor(RoleEnum $role = RoleEnum::SuperAdmin): User
{
    return User::factory()->create()->assignRole($role->value);
}

function latestActivity(string $event, ?string $subjectType = null): Activity
{
    return Activity::query()
        ->where('event', $event)
        ->when($subjectType !== null, fn ($query) => $query->where('subject_type', $subjectType))
        ->latest('id')
        ->firstOrFail();
}

it('keeps the activity log behind activity.view', function () {
    $this->get(route('activity-logs.index'))->assertRedirect(route('login'));

    $this->actingAs(activityActor(RoleEnum::Reader))->get(route('activity-logs.index'))->assertForbidden();
});

it('shows the activity log to admins and super-admins', function (RoleEnum $role) {
    $this->actingAs(activityActor($role))->get(route('activity-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/activity-logs/index')
            ->has('activityLogs.data')
            ->where('logNames', ['default', 'auth', 'settings'])
            ->where('events', fn ($events) => collect($events)->contains('settings_updated')));
})->with([RoleEnum::Admin, RoleEnum::SuperAdmin]);

it('shows the activity log to a custom role with activity.view', function () {
    Role::create(['name' => 'auditor'])->givePermissionTo(PermissionEnum::ActivityView->value);

    $this->actingAs(User::factory()->create()->assignRole('auditor'))
        ->get(route('activity-logs.index'))
        ->assertOk();
});

it('shares the activity sidebar module and its permission with allowed users only', function () {
    $this->actingAs(activityActor(RoleEnum::Admin))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projectSettings.sidebarModules.3', ['id' => 'activity', 'enabled' => true, 'locked' => false])
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains(PermissionEnum::ActivityView->value)));

    $this->actingAs(activityActor(RoleEnum::Reader))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => ! collect($permissions)->contains(PermissionEnum::ActivityView->value)));
});

it('logs user creation, updates, role changes and deletion with the acting user', function () {
    $admin = activityActor(RoleEnum::Admin);

    $this->actingAs($admin)->post(route('users.store'), [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        'roles' => [RoleEnum::Reader->value],
        'is_active' => true,
    ])->assertRedirect(route('users.index'));

    $user = User::query()->where('email', 'grace@example.com')->sole();
    $created = latestActivity('created', $user->getMorphClass());

    expect($created->causer_id)->toBe($admin->id)
        ->and($created->subject_id)->toBe($user->id)
        ->and($created->attribute_changes['attributes']['email'])->toBe('grace@example.com')
        ->and($created->attribute_changes['attributes'])->not->toHaveKey('password')
        ->and(latestActivity('roles_synced')->properties['role_names'])->toBe([RoleEnum::Reader->value]);

    $this->actingAs($admin)->put(route('users.update', $user), [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'roles' => [RoleEnum::Admin->value],
        'is_active' => false,
    ])->assertRedirect(route('users.index'));

    $updated = latestActivity('updated', $user->getMorphClass());
    $rolesSynced = latestActivity('roles_synced');

    expect($updated->causer_id)->toBe($admin->id)
        ->and($updated->attribute_changes['attributes']['is_active'])->toBeFalse()
        ->and($updated->attribute_changes['old']['is_active'])->toBeTrue()
        ->and($rolesSynced->causer_id)->toBe($admin->id)
        ->and($rolesSynced->properties['role_names'])->toBe([RoleEnum::Admin->value]);

    $this->actingAs($admin)->delete(route('users.destroy', $user))->assertRedirect(route('users.index'));

    expect(latestActivity('deleted', $user->getMorphClass()))
        ->causer_id->toBe($admin->id)
        ->subject_id->toBe($user->id);
});

it('logs group changes with their members and roles', function () {
    $admin = activityActor(RoleEnum::Admin);
    $member = activityActor(RoleEnum::Reader);

    $this->actingAs($admin)->post(route('groups.store'), [
        'name' => 'viewers',
        'roles' => [RoleEnum::Reader->value],
        'users' => [$member->id],
    ])->assertRedirect(route('groups.index'));

    $group = UserGroup::query()->where('name', 'viewers')->sole();

    expect(latestActivity('created', $group->getMorphClass()))
        ->causer_id->toBe($admin->id)
        ->and(latestActivity('members_synced')->properties['user_ids'])->toBe([$member->id])
        ->and(latestActivity('roles_synced', $group->getMorphClass())->properties['role_names'])->toBe([RoleEnum::Reader->value]);

    $this->actingAs($admin)->put(route('groups.update', $group), [
        'name' => 'readers',
        'roles' => [],
        'users' => [],
    ])->assertRedirect(route('groups.index'));

    $updated = latestActivity('updated', $group->getMorphClass());

    expect($updated->attribute_changes['attributes']['name'])->toBe('readers')
        ->and($updated->attribute_changes['old']['name'])->toBe('viewers')
        ->and(latestActivity('members_synced')->properties['user_ids'])->toBe([]);

    $this->actingAs($admin)->delete(route('groups.destroy', $group))->assertRedirect(route('groups.index'));

    expect(latestActivity('deleted', $group->getMorphClass())->causer_id)->toBe($admin->id);
});

it('logs role changes with their permissions', function () {
    $owner = activityActor();

    $this->actingAs($owner)->post(route('roles.store'), [
        'name' => 'operator',
        'permissions' => [PermissionEnum::ProjectsView->value, PermissionEnum::ChecksRun->value],
    ])->assertRedirect(route('roles.index'));

    $role = Role::findByName('operator');

    expect(latestActivity('created', $role->getMorphClass()))
        ->causer_id->toBe($owner->id)
        ->and(latestActivity('permissions_synced')->properties['permission_names'])->toBe(['checks.run', 'projects.view']);

    $this->actingAs($owner)->put(route('roles.update', $role), [
        'name' => 'runner',
        'permissions' => [PermissionEnum::ChecksRun->value],
    ])->assertRedirect(route('roles.index'));

    expect(latestActivity('updated', $role->getMorphClass())->attribute_changes['attributes']['name'])->toBe('runner')
        ->and(latestActivity('permissions_synced')->properties['permission_names'])->toBe(['checks.run']);

    $this->actingAs($owner)->delete(route('roles.destroy', $role))->assertRedirect(route('roles.index'));

    expect(latestActivity('deleted', $role->getMorphClass())->causer_id)->toBe($owner->id);
});

it('logs every project settings section with the saved keys', function (string $route, array $payload, string $section) {
    $owner = activityActor();

    $this->actingAs($owner)->put(route($route), $payload)->assertSessionHasNoErrors();

    $activity = latestActivity('settings_updated');

    expect($activity->log_name)->toBe('settings')
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->properties['section'])->toBe($section)
        ->and($activity->properties['keys'])->toContain(array_key_first($payload));
})->with([
    'general' => ['project.update', ['app_name' => 'Acme', 'reports_kept_per_project' => 10, 'stale_after_hours' => 12, 'runtime_retention_days' => 14, 'exceptions_retention_days' => 90, 'activity_retention_days' => 365], 'general'],
    'localization' => ['project.localization.update', ['default_language' => 'de'], 'localization'],
    'authentication & security' => ['project.security.update', ['password_policy' => 'medium', 'login_max_attempts' => 3, 'two_factor_required' => '0'], 'security'],
    'appearance' => ['appearance.update', ['default_appearance' => 'dark'], 'appearance'],
    'sidebar' => ['appearance.sidebar.update', ['sidebar_modules' => json_encode([['id' => 'dashboard', 'enabled' => true]])], 'sidebar'],
]);

it('never logs the OIDC client secret', function () {
    $owner = activityActor();
    $payload = [
        'password_policy' => 'medium',
        'login_max_attempts' => 3,
        'two_factor_required' => '0',
        'oidc_enabled' => '1',
        'oidc_issuer' => 'https://id.example.com',
        'oidc_client_id' => 'excubia',
        'oidc_client_secret' => 'super-secret-value',
    ];

    $this->actingAs($owner)->put(route('project.security.update'), $payload)->assertSessionHasNoErrors();

    $activity = latestActivity('settings_updated');

    expect($activity->properties['secret_keys_updated'])->toBeTrue()
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('super-secret-value');

    $this->actingAs($owner)->put(route('project.security.update'), [...$payload, 'oidc_client_secret' => '']);

    expect(latestActivity('settings_updated')->properties['secret_keys_updated'])->toBeFalse();
});

it('logs logins, logouts and failed logins', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'wrong-password']);

    $failed = latestActivity('failed');

    expect($failed->log_name)->toBe('auth')
        ->and($failed->causer_id)->toBeNull()
        ->and($failed->properties['identifier'])->toBe('ada@example.com')
        ->and(json_encode($failed->properties))->not->toContain('wrong-password');

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'password']);

    expect(latestActivity('login'))->causer_id->toBe($user->id)->log_name->toBe('auth')
        ->and(latestActivity('login')->properties['ip'])->toBe('127.0.0.1');

    $this->post(route('logout'));

    expect(latestActivity('logout')->causer_id)->toBe($user->id);
});

it('filters the activity log by user, action, log, date and search', function () {
    $owner = activityActor();
    $other = activityActor(RoleEnum::Admin);

    activity()->causedBy($owner)->event('settings_updated')->useLog('settings')->log('Project settings updated');
    activity()->causedBy($other)->event('login')->useLog('auth')->log('User logged in');
    Carbon::setTestNow(now()->subDays(10));
    activity()->causedBy($other)->event('logout')->useLog('auth')->log('User logged out');
    Carbon::setTestNow();

    $descriptions = fn (array $query) => fn (Assert $page) => $page
        ->where('activityLogs.data', fn ($rows) => collect($rows)->pluck('description')->sort()->values()->all() === $query['expected']);

    $cases = [
        [['user_ids' => [$other->id]], ['User logged in', 'User logged out']],
        [['user_id' => $owner->id], ['Project settings updated']],
        [['event' => 'login'], ['User logged in']],
        [['log_name' => 'settings'], ['Project settings updated']],
        [['log_name' => 'auth', 'date_from' => now()->subDay()->toDateString()], ['User logged in']],
        [['log_name' => 'auth', 'date_to' => now()->subDays(5)->toDateString()], ['User logged out']],
        [['search' => 'logged out'], ['User logged out']],
    ];

    foreach ($cases as [$filters, $expected]) {
        $this->actingAs($owner)->get(route('activity-logs.index', $filters))
            ->assertOk()
            ->assertInertia($descriptions(['expected' => $expected]));
    }

    $this->actingAs($owner)->get(route('activity-logs.index', ['user_ids' => [$other->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.user_ids', [$other->id])
            ->where('users', [['id' => $other->id, 'name' => $other->name, 'email' => $other->email]])
            ->where('activityLogs.data.0.causer.email', $other->email));
});

it('ignores an unknown action filter', function () {
    $this->actingAs(activityActor())->get(route('activity-logs.index', ['event' => 'ai_prompt']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.event', ''));
});
