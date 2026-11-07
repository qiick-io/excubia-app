<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Actions\StorePushedReportAction;
use App\Enums\PermissionEnum;
use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\ProjectReportController;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\Finding;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Spatie\Permission\Models\Role;

/**
 * Security review (#51): walks every named route of the app as a low-privilege user and as a
 * non-admin user holding every permission, against a project restricted to admins and its
 * children. Nothing about that project may be read or changed, and a reader may change nothing.
 */
const HIDDEN_PROJECT_NAME = 'Classified Vault';

const HIDDEN_PROJECT_SLUG = 'classified-vault';

/**
 * Account routes every signed-in user may call on their own account; a reader changing its own
 * profile is not a finding.
 */
const SELF_SERVICE_ROUTES = ['profile.update', 'profile.destroy', 'locale.update', 'user-password.update', 'profile.alerts.update', 'profile.alerts.test'];

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-05 12:00:00');
    config(['excubia.ai_enabled' => true]);

    $this->admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->hidden = Project::factory()->create(['name' => HIDDEN_PROJECT_NAME, 'slug' => HIDDEN_PROJECT_SLUG, 'visibility' => ProjectVisibilityEnum::Admins]);
    $this->visible = Project::factory()->create(['name' => 'Public Shop', 'slug' => 'public-shop']);
    app(RotateProjectTokenAction::class)->execute($this->hidden);

    app(StorePushedReportAction::class)->execute($this->hidden, (string) Str::uuid(), Date::now(), [
        'schemaVersion' => 1,
        'checks' => [[
            'checkId' => 'composer-audit', 'name' => 'Composer audit', 'status' => 'fail',
            'findings' => [[
                'checkId' => 'composer-audit', 'severity' => 'critical', 'status' => 'fail', 'subject' => 'vault/secret-package',
                'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null,
            ]],
        ]],
        'upgradePlan' => [],
    ]);

    $this->children = [
        'finding' => Finding::query()->where('project_id', $this->hidden->id)->firstOrFail(),
        'exceptionGroup' => ExceptionGroup::query()->create([
            'project_id' => $this->hidden->id, 'fingerprint' => str_repeat('a', 64), 'environment' => 'production', 'class' => 'RuntimeException',
            'message' => 'vault failure', 'frames' => [], 'previous' => [], 'context_kind' => 'request', 'context_name' => 'vault.open',
            'count' => 1, 'first_seen' => Date::now(), 'last_seen' => Date::now(), 'status' => 'open',
        ]),
        'alertMute' => AlertMute::query()->create(['project_id' => $this->hidden->id, 'event' => 'project.stale']),
        'maintenanceWindow' => MaintenanceWindow::query()->create([
            'project_id' => $this->hidden->id, 'name' => 'Vault night', 'recurrence' => 'weekly', 'weekdays' => [2],
            'start_time' => '02:00', 'duration_minutes' => 60, 'timezone' => 'UTC',
        ]),
    ];
    $this->alert = AlertLog::query()->where('project_id', $this->hidden->id)->first();

    $this->conversation = Conversation::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'participant_type' => Conversation::participantType($this->admin),
        'participant_id' => Conversation::participantKey($this->admin),
        'title' => 'Admin chat about '.HIDDEN_PROJECT_NAME,
    ]);
    $this->group = UserGroup::query()->create(['name' => 'Ops']);
    $this->customRole = Role::query()->create(['name' => 'auditor', 'guard_name' => 'web']);
});

/**
 * Every named route the app itself defines (not Fortify, Passkeys or Inertia DevTools), one
 * entry per method and, for `{view}` routes, per tab.
 *
 * @return list<array{name: string, method: string, view: string|null, route: Route}>
 */
function appRoutes(): array
{
    $entries = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();
        $action = $route->getActionName();

        if ($name === null || str_starts_with($route->uri(), 'api/') || str_starts_with($name, 'setup.') || str_starts_with($name, 'auth.oidc.') || str_starts_with($name, 'storage.')
            || ! (str_starts_with($action, 'App\\') || $action === 'Closure')) {
            continue;
        }

        $views = match ($name) {
            'projects.view' => ProjectReportController::VIEWS,
            'fleet.view' => FleetController::VIEWS,
            default => [null],
        };

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS', 'QUERY']) as $method) {
            foreach ($views as $view) {
                $entries[] = ['name' => $name, 'method' => $method, 'view' => $view, 'route' => $route];
            }
        }
    }

    return $entries;
}

/**
 * The URL of a route with every parameter pointing at the hidden project, its children, or
 * records of other users.
 */
function hiddenTargetUrl(array $entry, Project $routeProject): string
{
    /** @var Route $route */
    $route = $entry['route'];
    $test = test();
    $values = [
        'project' => $route->bindingFieldFor('project') === 'slug' ? $routeProject->slug : $routeProject->id,
        'view' => $entry['view'],
        'finding' => $test->children['finding']->id,
        'exceptionGroup' => $test->children['exceptionGroup']->id,
        'alertMute' => $test->children['alertMute']->id,
        'maintenanceWindow' => $test->children['maintenanceWindow']->id,
        'user' => $test->admin->id,
        'group' => $test->group->id,
        'role' => $test->customRole->id,
        'conversation' => $test->conversation->id,
        'uuid' => (string) Str::uuid(),
        'asset' => 'logo',
        'channel' => 'webhook',
    ];
    $parameters = [];

    foreach ($route->parameterNames() as $parameter) {
        expect(array_key_exists($parameter, $values))->toBeTrue("No test value for route parameter {$parameter} of {$entry['name']}");
        $parameters[$parameter] = $values[$parameter];
    }

    return route($entry['name'], $parameters);
}

/**
 * A body that passes validation where it can, so a missing authorization check shows up as a
 * success instead of hiding behind a validation error.
 *
 * @return array<string, mixed>
 */
function plausibleBody(?AlertLog $alert): array
{
    return [
        'name' => 'Renamed', 'slug' => 'renamed', 'is_enabled' => true, 'visibility' => 'everyone',
        'status' => 'ignored', 'is_dismissed' => true, 'reason' => 'Accepted risk',
        'alert_id' => $alert?->id, 'scope' => 'rule', 'duration' => '1h',
        'recurrence' => 'weekly', 'timezone' => 'UTC', 'weekdays' => [1], 'start_time' => '02:00', 'duration_minutes' => 30,
        'message' => 'hello', 'ids' => [], 'permissions' => [], 'roles' => ['reader'], 'users' => [],
        'first_name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'Password-123456!', 'password_confirmation' => 'Password-123456!',
    ];
}

it('walks every named route as a reader without reading the hidden project or changing anything', function () {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $failures = [];

    foreach (appRoutes() as $entry) {
        if ($entry['method'] !== 'GET' && in_array($entry['name'], SELF_SERVICE_ROUTES, true)) {
            continue;
        }

        $url = hiddenTargetUrl($entry, $this->hidden);
        $response = $this->actingAs($reader)->call($entry['method'], $url, $entry['method'] === 'GET' ? [] : plausibleBody($this->alert));
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        $label = "{$entry['method']} {$entry['name']} ({$url}) → {$status}";

        if ($entry['method'] !== 'GET' && ! in_array($status, [403, 404], true)) {
            $failures[] = $label.' (a reader changed something)';
        }

        if (str_contains($content, HIDDEN_PROJECT_NAME) || str_contains($content, HIDDEN_PROJECT_SLUG)) {
            $failures[] = $label.' (the hidden project leaked)';
        }

        if ($status >= 500) {
            $failures[] = $label.' (server error)';
        }
    }

    expect($failures)->toBe([])
        ->and($this->hidden->fresh()->name)->toBe(HIDDEN_PROJECT_NAME)
        ->and($this->children['finding']->fresh()->dismissed_at)->toBeNull()
        ->and($this->children['exceptionGroup']->fresh()->status->value)->toBe('open')
        ->and(AlertMute::query()->whereKey($this->children['alertMute']->id)->exists())->toBeTrue()
        ->and(MaintenanceWindow::query()->whereKey($this->children['maintenanceWindow']->id)->exists())->toBeTrue();
});

it('keeps the hidden project and its children out of reach of a non-admin holding every permission', function () {
    $role = Role::query()->create(['name' => 'operator', 'guard_name' => 'web']);
    $role->syncPermissions(PermissionEnum::values());
    $operator = User::factory()->create()->assignRole('operator');
    $failures = [];

    foreach (appRoutes() as $entry) {
        $parameters = $entry['route']->parameterNames();
        $touchesProject = array_intersect($parameters, ['project', 'finding', 'exceptionGroup', 'alertMute', 'maintenanceWindow', 'conversation']) !== [];

        if (! $touchesProject) {
            continue;
        }

        // The hidden project itself, and its children under a project the operator may edit.
        foreach (array_unique([$this->hidden->id, in_array('project', $parameters, true) && array_intersect($parameters, ['finding', 'exceptionGroup', 'alertMute', 'maintenanceWindow']) !== [] ? $this->visible->id : $this->hidden->id]) as $projectId) {
            $routeProject = Project::query()->findOrFail($projectId);
            $url = hiddenTargetUrl($entry, $routeProject);
            $response = $this->actingAs($operator)->call($entry['method'], $url, $entry['method'] === 'GET' ? [] : plausibleBody($this->alert));
            $status = $response->getStatusCode();
            $isRejected = in_array($status, [403, 404], true)
                // A child of another project fails validation (exists rule scoped to the project).
                || ($status === 302 && session()->has('errors'));

            if (! $isRejected) {
                $failures[] = "{$entry['method']} {$entry['name']} ({$url}) → {$status}";
            }

            session()->forget('errors');
        }
    }

    expect($failures)->toBe([])
        ->and($this->hidden->fresh()->name)->toBe(HIDDEN_PROJECT_NAME)
        ->and($this->hidden->fresh()->push_token_hash)->not->toBeNull()
        ->and($this->children['finding']->fresh()->dismissed_at)->toBeNull()
        ->and($this->children['exceptionGroup']->fresh()->status->value)->toBe('open');
});

it('keeps the hidden project off the install-wide pages for a non-admin holding every permission', function () {
    // Something about both projects on each install-wide page: pushes, skipped runtime types,
    // a failed alert delivery, activity log entries (created, token rotated) and a failed job.
    foreach ([$this->hidden, $this->visible] as $project) {
        $project->forceFill(['skipped_runtime_aggregates' => 3])->save();
        AlertLog::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'event' => 'project.stale', 'severity' => 'warning',
            'summary' => $project->name.' is stale', 'status' => AlertLog::FAILED, 'deliveries' => ['webhook' => AlertLog::FAILED],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => DeliverAlertJob::class, 'data' => ['command' => serialize(new DeliverAlertJob('webhook', ['project' => ['name' => $project->name, 'slug' => $project->slug]]))]]),
            'exception' => 'RuntimeException: Alert delivery to webhook failed: HTTP 500', 'failed_at' => Date::now(),
        ]);
    }

    $role = Role::query()->create(['name' => 'operator', 'guard_name' => 'web']);
    $role->syncPermissions(PermissionEnum::values());
    $operator = User::factory()->create()->assignRole('operator');

    foreach (['system.index', 'activity-logs.index', 'jobs.index'] as $name) {
        $content = (string) $this->actingAs($operator)->get(route($name))->assertOk()->getContent();

        expect(str_contains($content, HIDDEN_PROJECT_NAME) || str_contains($content, HIDDEN_PROJECT_SLUG))->toBeFalse("{$name} leaked the hidden project");
    }

    // The pages still list the visible project to the operator, and everything to an admin.
    expect((string) $this->actingAs($operator)->get(route('system.index'))->getContent())->toContain('Public Shop')
        ->and((string) $this->actingAs($operator)->get(route('activity-logs.index'))->getContent())->toContain('Public Shop')
        ->and((string) $this->actingAs($this->admin)->get(route('system.index'))->getContent())->toContain(HIDDEN_PROJECT_NAME)
        ->and((string) $this->actingAs($this->admin)->get(route('activity-logs.index'))->getContent())->toContain(HIDDEN_PROJECT_NAME);
});

it('does not let a user continue or read another user\'s assistant conversation', function () {
    $role = Role::query()->create(['name' => 'ai-user', 'guard_name' => 'web']);
    $role->syncPermissions([PermissionEnum::CanUseAi->value, PermissionEnum::CanShowProjects->value]);
    $user = User::factory()->create()->assignRole('ai-user');

    $this->actingAs($user)->get(route('ai.show', $this->conversation->id))->assertNotFound();
    $this->actingAs($user)->postJson(route('ai.store'), ['message' => 'hi', 'conversation_id' => $this->conversation->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('conversation_id');
    $this->actingAs($user)->delete(route('ai.conversations.destroy'), ['ids' => [$this->conversation->id]]);

    expect(Conversation::query()->whereKey($this->conversation->id)->exists())->toBeTrue();
});
