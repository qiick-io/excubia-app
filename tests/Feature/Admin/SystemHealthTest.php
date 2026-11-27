<?php

declare(strict_types=1);

use App\Actions\RotateProjectTokenAction;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Jobs\RecordQueueHeartbeatJob;
use App\Models\AlertLog;
use App\Models\IngestRejection;
use App\Models\Project;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Qiick\Excubia\Laravel\ExcubiaHeartbeatCommand;
use Spatie\Permission\Models\Role;

/**
 * The System page (#117) at `/settings/system`, behind `can-show-system`, and the push
 * rejection counters it reads, recorded without body, token or project.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Date::setTestNow('2026-11-07 12:00:00');
    $this->seed(PermissionSeeder::class);
});

function systemPageUser(RoleEnum|string $role): User
{
    return User::factory()->create()->assignRole($role instanceof RoleEnum ? $role->value : $role);
}

it('is shown to super-admins and to roles with can-show-system only', function () {
    expect(route('system.index', absolute: false))->toBe('/settings/system');

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))->get(route('system.index'))->assertOk();
    $this->actingAs(systemPageUser(RoleEnum::Admin))->get(route('system.index'))->assertForbidden();
    $this->actingAs(systemPageUser(RoleEnum::Reader))->get(route('system.index'))->assertForbidden();

    Role::findOrCreate('operator')->givePermissionTo(PermissionEnum::CanShowSystem->value);
    $this->actingAs(systemPageUser('operator'))->get(route('system.index'))->assertOk();
});

it('redirects guests to the login page', function () {
    $this->get(route('system.index'))->assertRedirect(route('login'));
});

it('reports a stopped scheduler and an unknown queue worker on a fresh install', function () {
    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/system/index')
            ->where('scheduler.status', 'error')
            ->where('scheduler.lastRunAt', null)
            ->where('scheduler.hint', 'scheduler-heartbeat')
            ->where('rejections.status', 'ok')
            ->where('rollups.isBackfillPending', false)
            ->where('version.coreConstraint', '^1.0@beta')
            ->where('version.protocolVersions', [1])
            ->has('docsUrl'));
});

it('shows the heartbeats, pushes, skipped runtime, alert failures and backfill state', function () {
    config(['queue.default' => 'database']);
    Cache::forever(ExcubiaHeartbeatCommand::CACHE_KEY, Date::now()->subMinute()->getTimestamp());
    Cache::forever(RecordQueueHeartbeatJob::CACHE_KEY, Date::now()->subMinutes(10)->getTimestamp());

    $shop = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $shop->forceFill(['last_report_at' => Date::now()->subDays(3), 'skipped_runtime_aggregates' => 4])->save();
    PushedBatch::query()->create(['project_id' => $shop->id, 'type' => 'runtime', 'batch_id' => (string) Str::uuid(), 'received_at' => Date::now()->subHour()]);
    PushedBatch::query()->create(['project_id' => $shop->id, 'type' => 'runtime', 'batch_id' => (string) Str::uuid(), 'received_at' => Date::now()->subMinutes(30)]);
    PushedBatch::query()->create(['project_id' => $shop->id, 'type' => 'exceptions', 'batch_id' => (string) Str::uuid(), 'received_at' => Date::now()->subMinutes(5)]);

    AlertLog::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $shop->id, 'event' => 'project.stale', 'severity' => 'high', 'summary' => 'Shop stopped reporting', 'status' => AlertLog::FAILED, 'deliveries' => ['slack' => 'failed', 'webhook' => 'sent']]);
    AlertLog::query()->create(['uuid' => (string) Str::uuid(), 'project_id' => $shop->id, 'event' => 'project.stale', 'severity' => 'high', 'summary' => 'Shop', 'status' => AlertLog::SENT, 'deliveries' => ['slack' => 'sent']]);

    // Runtime from a day before any hourly rollup: the backfill has not run.
    RuntimeAggregate::query()->create(['project_id' => $shop->id, 'type' => 'request', 'bucket' => Date::now()->subDay(), 'sample_rate' => 1, 'dimension_key' => str_repeat('a', 64), 'dimensions' => [], 'count' => 1, 'error_count' => 0]);

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scheduler.status', 'ok')
            ->where('queue.status', 'error')
            ->where('queue.connection', 'database')
            ->where('queue.failed', 0)
            ->where('pushes.status', 'warning')
            ->where('pushes.projects.0.name', 'Shop')
            ->where('pushes.projects.0.isReportStale', true)
            ->where('pushes.projects.0.runtimeAt', Date::now()->subMinutes(30)->toIso8601String())
            ->where('pushes.projects.0.isRuntimeStale', true)
            ->where('pushes.projects.0.exceptionsAt', Date::now()->subMinutes(5)->toIso8601String())
            ->where('skippedRuntime.status', 'warning')
            ->where('skippedRuntime.projects.0.count', 4)
            ->where('alertFailures.total', 1)
            ->where('alertFailures.latest.0.channels', ['slack'])
            ->where('rollups.status', 'warning')
            ->where('rollups.isBackfillPending', true));

    $this->artisan('excubia:backfill-runtime-rollups')->assertSuccessful();

    $this->get(route('system.index'))->assertInertia(fn (Assert $page) => $page->where('rollups.isBackfillPending', false));
});

it('runs the queue heartbeat job and marks the worker alive', function () {
    config(['queue.default' => 'database']);
    Cache::forever(ExcubiaHeartbeatCommand::CACHE_KEY, Date::now()->getTimestamp());
    (new RecordQueueHeartbeatJob)->handle();

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page->where('queue.status', 'ok')->where('queue.pending', 0));
});

it('counts push rejections per endpoint, status and reason, without body, token or project', function () {
    $project = Project::factory()->create();
    $token = app(RotateProjectTokenAction::class)->execute($project);

    $this->postJson('/api/runtime', ['secret' => 'payload-value'], ['Authorization' => 'Bearer wrong-token'])->assertUnauthorized();
    $this->postJson('/api/runtime', ['secret' => 'payload-value'])->assertUnauthorized();
    $this->postJson('/api/reports', ['protocolVersion' => 1, 'type' => 'report', 'secret' => 'payload-value'], ['Authorization' => "Bearer {$token}"])->assertUnprocessable();
    $this->postJson('/api/runtime', ['protocolVersion' => 1, 'type' => 'runtime', 'batchId' => (string) Str::uuid(), 'sentAt' => '2026-11-07T11:59:00Z', 'runtime' => ['bucketSeconds' => 60, 'aggregates' => [['type' => 'request', 'count' => 'many']]]], ['Authorization' => "Bearer {$token}"])->assertUnprocessable();

    $project->forceFill(['is_enabled' => false])->save();
    $this->postJson('/api/exceptions', [], ['Authorization' => "Bearer {$token}"])->assertForbidden();

    $rows = IngestRejection::query()->orderBy('id')->get(['endpoint', 'status', 'reason', 'count'])->toArray();

    expect($rows)->toContain(['endpoint' => 'runtime', 'status' => 401, 'reason' => 'token', 'count' => 2])
        ->and($rows)->toContain(['endpoint' => 'exceptions', 'status' => 403, 'reason' => 'disabled', 'count' => 1])
        ->and(collect($rows)->firstWhere('endpoint', 'reports')['status'])->toBe(422)
        ->and(collect($rows)->firstWhere(fn (array $row): bool => $row['endpoint'] === 'runtime' && $row['status'] === 422)['reason'])
        ->toMatch('/^runtime\.aggregates\.\*\./');

    $stored = json_encode(IngestRejection::query()->get()->toArray());
    expect($stored)->not->toContain('payload-value')
        ->and($stored)->not->toContain('wrong-token')
        ->and($stored)->not->toContain($token)
        ->and(Schema::getColumnListing('ingest_rejections'))->toBe(['id', 'endpoint', 'status', 'reason', 'bucket', 'count']);

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rejections.status', 'warning')
            ->where('rejections.total', 5)
            ->where('rejections.rows.0', ['endpoint' => 'runtime', 'status' => 401, 'reason' => 'token', 'count' => 2]));
});

it('counts throttled pushes, which the rate limit answers before the token check (#244)', function () {
    config(['excubia.push_requests_per_minute' => 1]);
    $token = app(RotateProjectTokenAction::class)->execute(Project::factory()->create());

    $this->postJson('/api/runtime', [], ['Authorization' => "Bearer {$token}"])->assertUnprocessable();
    $this->postJson('/api/runtime', [], ['Authorization' => "Bearer {$token}"])->assertTooManyRequests();
    $this->postJson('/api/runtime', [], ['Authorization' => "Bearer {$token}"])->assertTooManyRequests();

    expect(IngestRejection::query()->where('reason', 'throttled')->get(['endpoint', 'status', 'reason', 'count'])->toArray())
        ->toBe([['endpoint' => 'runtime', 'status' => 429, 'reason' => 'throttled', 'count' => 2]]);
});

it('prunes rejection counters older than two days and reads only the last 24 hours', function () {
    IngestRejection::query()->create(['endpoint' => 'reports', 'status' => 401, 'reason' => 'token', 'bucket' => Date::now()->subDays(3), 'count' => 7]);
    IngestRejection::query()->create(['endpoint' => 'reports', 'status' => 401, 'reason' => 'token', 'bucket' => Date::now()->subHours(30), 'count' => 2]);

    $this->artisan('excubia:prune-telemetry')->assertSuccessful();

    expect((int) IngestRejection::query()->sum('count'))->toBe(2);

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page->where('rejections.total', 0)->where('rejections.status', 'ok'));
});

it('shows the self-monitoring runtime types and warns about unknown names (#219)', function () {
    config(['excubia.self_monitoring' => true, 'excubia.self_monitoring_types' => null]);

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selfMonitoring.status', 'ok')
            ->where('selfMonitoring.hint', 'self-monitoring')
            ->where('selfMonitoring.isEnabled', true)
            ->where('selfMonitoring.source', 'default')
            ->where('selfMonitoring.unknown', [])
            ->where('selfMonitoring.types', fn ($types): bool => collect($types)->every(fn (array $type): bool => $type['isEnabled'])
                && collect($types)->firstWhere('type', 'health') !== null));

    config(['excubia.self_monitoring_types' => 'server,health,queuewait']);

    $this->actingAs(systemPageUser(RoleEnum::SuperAdmin))
        ->get(route('system.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selfMonitoring.status', 'warning')
            ->where('selfMonitoring.source', 'environment')
            ->where('selfMonitoring.unknown', ['queuewait'])
            ->where('selfMonitoring.types', fn ($types): bool => collect($types)->where('isEnabled', true)->pluck('type')->all() === ['server', 'health']));
});

it('flags the example super-admin on a server, as an error while it keeps the password "password"', function () {
    $viewer = systemPageUser(RoleEnum::SuperAdmin);
    $example = User::factory()->create(['email' => 'superadmin@example.com', 'password' => 'password']);
    $page = fn () => $this->actingAs($viewer)->get(route('system.index'));

    $page()->assertInertia(fn (Assert $page) => $page
        ->where('exampleAdmin.status', 'ok')
        ->where('exampleAdmin.exists', false)
        ->where('exampleAdmin.hint', 'example-admin-account'));

    app()->detectEnvironment(fn (): string => 'production');

    $page()->assertInertia(fn (Assert $page) => $page
        ->where('exampleAdmin.status', 'error')
        ->where('exampleAdmin.hasDefaultPassword', true));

    $example->forceFill(['password' => 'a-changed-long-password'])->save();

    $page()->assertInertia(fn (Assert $page) => $page
        ->where('exampleAdmin.status', 'warning')
        ->where('exampleAdmin.exists', true)
        ->where('exampleAdmin.hasDefaultPassword', false));

    $example->delete();

    $page()->assertInertia(fn (Assert $page) => $page->where('exampleAdmin.status', 'ok'));
});
