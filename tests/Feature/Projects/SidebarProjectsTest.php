<?php

declare(strict_types=1);

use App\Enums\ProjectVisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\User;
use App\Services\SidebarProjectsService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The projects under "Projects" in the sidebar (#273): pins in pin order, then up to five other
 * projects with open problems, worst first; only projects the user may view.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-11 09:00:00');
});

function sidebarOpenException(Project $project, string $fingerprint = 'a'): void
{
    ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat($fingerprint, 64), 'environment' => 'production',
        'class' => 'RuntimeException', 'message' => 'Payment failed', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(), 'status' => 'open',
    ]);
}

function sidebarFinding(Project $project, string $severity): void
{
    $project->findings()->create([
        'fingerprint' => hash('sha256', $project->slug.$severity), 'check_id' => 'composer-audit', 'severity' => $severity, 'status' => 'open',
        'finding' => ['checkId' => 'composer-audit', 'checkName' => 'Composer audit', 'severity' => $severity, 'status' => 'fail', 'subject' => 'vendor/package', 'message' => 'Known vulnerability', 'fixHint' => '', 'evidenceUrl' => null],
        'first_seen_at' => Date::now(), 'last_seen_at' => Date::now(),
    ]);
}

it('pins and unpins a project, in pin order, shown in the shared prop', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $billing = Project::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    $api = Project::factory()->create(['name' => 'API', 'slug' => 'api']);

    $this->actingAs($admin)->post(route('projects.pin.store', $billing->slug))->assertRedirect();
    $this->actingAs($admin)->post(route('projects.pin.store', $api->slug))->assertRedirect();
    $this->actingAs($admin)->post(route('projects.pin.store', $billing->slug))->assertRedirect();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sidebarProjects.pinned', [
                ['slug' => 'billing', 'name' => 'Billing', 'logo_url' => null, 'status' => 'good'],
                ['slug' => 'api', 'name' => 'API', 'logo_url' => null, 'status' => 'good'],
            ]));

    $this->actingAs($admin)->delete(route('projects.pin.destroy', $billing->slug))->assertRedirect();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('sidebarProjects.pinned.0.slug', 'api')->has('sidebarProjects.pinned', 1));
});

it('keeps at most eight pins', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $projects = Project::factory()->count(SidebarProjectsService::PINNED_LIMIT + 1)->create();

    foreach ($projects->take(SidebarProjectsService::PINNED_LIMIT) as $project) {
        $this->actingAs($admin)->post(route('projects.pin.store', $project->slug))->assertSessionHasNoErrors();
    }

    $this->actingAs($admin)->post(route('projects.pin.store', $projects->last()->slug))->assertSessionHasErrors('project');

    expect($admin->pinnedProjects()->count())->toBe(SidebarProjectsService::PINNED_LIMIT);
});

it('refuses to pin a project the user cannot see, and drops a pin that became hidden', function () {
    $reader = User::factory()->create()->assignRole(RoleEnum::Reader->value);
    $hidden = Project::factory()->create(['slug' => 'hidden', 'visibility' => ProjectVisibilityEnum::Admins]);
    $open = Project::factory()->create(['slug' => 'open']);

    $this->actingAs($reader)->post(route('projects.pin.store', $hidden->slug))->assertForbidden();
    $this->actingAs($reader)->post(route('projects.pin.store', $open->slug))->assertRedirect();

    $open->update(['visibility' => ProjectVisibilityEnum::Admins]);
    SidebarProjectsService::forget($reader);

    expect(app(SidebarProjectsService::class)->forUser($reader)['pinned'])->toBe([]);
});

it('lists troubled projects that are not pinned, critical first, capped at five', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $pinned = Project::factory()->create(['name' => 'Pinned', 'slug' => 'pinned']);
    sidebarOpenException($pinned);
    $warning = Project::factory()->create(['name' => 'Aardvark', 'slug' => 'aardvark']);
    sidebarFinding($warning, 'medium');
    $critical = Project::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
    sidebarFinding($critical, 'critical');
    Project::factory()->create(['name' => 'Clean', 'slug' => 'clean']);
    $this->actingAs($admin)->post(route('projects.pin.store', $pinned->slug));

    $sidebar = app(SidebarProjectsService::class)->forUser($admin);

    expect($sidebar['pinned'])->toBe([['slug' => 'pinned', 'name' => 'Pinned', 'logo_url' => null, 'status' => 'critical']])
        ->and($sidebar['needsAttention'])->toBe([
            ['slug' => 'zebra', 'name' => 'Zebra', 'logo_url' => null, 'status' => 'critical'],
            ['slug' => 'aardvark', 'name' => 'Aardvark', 'logo_url' => null, 'status' => 'warning'],
        ]);

    foreach (range(1, 6) as $index) {
        sidebarOpenException(Project::factory()->create(), (string) $index);
    }

    SidebarProjectsService::forget($admin);

    expect(app(SidebarProjectsService::class)->forUser($admin)['needsAttention'])->toHaveCount(SidebarProjectsService::ATTENTION_LIMIT);
});
