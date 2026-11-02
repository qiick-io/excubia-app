<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Queue monitor (`/settings/jobs`) for the `database` queue, behind `jobs.manage`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function jobsPageUser(RoleEnum|string $role): User
{
    return User::factory()->create()->assignRole($role instanceof RoleEnum ? $role->value : $role);
}

function jobPayload(string $uuid, string $displayName): string
{
    return (string) json_encode([
        'uuid' => $uuid,
        'displayName' => $displayName,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'attempts' => 3,
        'data' => ['commandName' => $displayName, 'command' => 'O:8:"stdClass":0:{}'],
    ]);
}

function insertFailedJob(string $displayName = 'App\Jobs\RunChecksJob'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => jobPayload($uuid, $displayName),
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('serves the jobs page under settings', function () {
    $uuid = (string) Str::uuid();

    expect(route('jobs.index', absolute: false))->toBe('/settings/jobs')
        ->and(route('jobs.retry', $uuid, absolute: false))->toBe("/settings/jobs/{$uuid}/retry")
        ->and(route('jobs.destroy', $uuid, absolute: false))->toBe("/settings/jobs/{$uuid}");
});

it('keeps the jobs page behind jobs.manage', function (RoleEnum $role) {
    $user = jobsPageUser($role);
    $uuid = insertFailedJob();

    $this->actingAs($user)->get(route('jobs.index'))->assertForbidden();
    $this->actingAs($user)->post(route('jobs.retry', $uuid))->assertForbidden();
    $this->actingAs($user)->delete(route('jobs.destroy', $uuid))->assertForbidden();

    expect(DB::table('failed_jobs')->count())->toBe(1);
})->with([RoleEnum::Admin, RoleEnum::Reader]);

it('sends guests to the login page', function () {
    $this->get(route('jobs.index'))->assertRedirect(route('login'));
});

it('lets a custom role with jobs.manage open the jobs page', function () {
    Role::create(['name' => 'operator'])->givePermissionTo(PermissionEnum::JobsManage->value);

    $this->actingAs(jobsPageUser('operator'))->get(route('jobs.index'))->assertOk();
});

it('lists queued and failed jobs', function () {
    $pendingUuid = (string) Str::uuid();
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => jobPayload($pendingUuid, 'App\Jobs\SyncProjectJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);
    $failedUuid = insertFailedJob();

    $this->actingAs(jobsPageUser(RoleEnum::SuperAdmin))
        ->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/jobs/index')
            ->where('pending_count', 1)
            ->has('pending', 1)
            ->where('pending.0.display_name', 'App\Jobs\SyncProjectJob')
            ->where('pending.0.queue', 'default')
            ->has('failed', 1)
            ->where('failed.0.uuid', $failedUuid)
            ->where('failed.0.display_name', 'App\Jobs\RunChecksJob')
            ->where('failed.0.exception', 'RuntimeException: boom'));
});

it('moves a retried failed job back to the queue', function () {
    $uuid = insertFailedJob();

    $this->actingAs(jobsPageUser(RoleEnum::SuperAdmin))
        ->post(route('jobs.retry', $uuid))
        ->assertRedirect(route('jobs.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['uuid'])->toBe($uuid)
        ->and($payload['attempts'])->toBe(0);
});

it('deletes a failed job without retrying it', function () {
    $uuid = insertFailedJob();
    $kept = insertFailedJob();

    $this->actingAs(jobsPageUser(RoleEnum::SuperAdmin))
        ->delete(route('jobs.destroy', $uuid))
        ->assertRedirect(route('jobs.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$kept])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('reports an unknown failed job', function (string $method, string $route) {
    $this->actingAs(jobsPageUser(RoleEnum::SuperAdmin))
        ->{$method}(route($route, (string) Str::uuid()))
        ->assertRedirect(route('jobs.index'))
        ->assertInertiaFlash('toast.type', 'error');
})->with([
    'retry' => ['post', 'jobs.retry'],
    'delete' => ['delete', 'jobs.destroy'],
]);

it('links the jobs page from the performance page for jobs.manage only', function () {
    $this->actingAs(jobsPageUser(RoleEnum::SuperAdmin))
        ->get(route('performance.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('links.jobs', route('jobs.index')));

    Role::create(['name' => 'settings-manager'])->givePermissionTo(PermissionEnum::SettingsManage->value);

    $this->actingAs(jobsPageUser('settings-manager'))
        ->get(route('performance.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('links.jobs', null));
});
