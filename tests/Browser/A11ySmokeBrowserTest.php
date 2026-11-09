<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Axe smoke for the main pages (#112), as in externa-core: fails on critical or serious
 * violations (`assertNoAccessibilityIssues(1)`) in light and dark, plus one high-contrast pass.
 * Runs in the `Accessibility` CI job, not in `php artisan test`.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('has no critical or serious axe issues on login in light, dark and high contrast', function () {
    foreach (['light', 'dark', 'high-contrast'] as $mode) {
        visitA11y('/login', $mode)
            ->assertPresent('#main-content')
            ->assertNoAccessibilityIssues(1)
            ->assertNoJavaScriptErrors();
    }
});

it('has no critical or serious axe issues on the first-run wizard in light, dark and high contrast', function () {
    // Admin step: a fresh install without users (#116).
    foreach (['light', 'dark', 'high-contrast'] as $mode) {
        visitA11y('/setup', $mode)
            ->assertPresent('#main-content')
            ->assertNoAccessibilityIssues(1)
            ->assertNoJavaScriptErrors();
    }

    // Project step: the new admin, no project yet.
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    foreach (['light', 'dark'] as $mode) {
        visitA11y('/setup', $mode)
            ->assertSee('Project name')
            ->assertNoAccessibilityIssues(1)
            ->assertNoJavaScriptErrors();
    }
});

it('has no critical or serious axe issues on the main authenticated pages in light and dark', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    $paths = [
        route('dashboard', absolute: false),
        route('projects.index', absolute: false),
        route('projects.show', $project, false),
        route('projects.view', [$project, 'findings'], false).'?finding='.rawurlencode('laravel-backups|s3|'),
        route('projects.view', [$project, 'licenses'], false),
        route('projects.view', [$project, 'runtime'], false),
        route('projects.view', [$project, 'ai'], false),
        route('projects.view', [$project, 'exceptions'], false),
        route('projects.view', [$project, 'schedule'], false),
        route('projects.view', [$project, 'alerts'], false),
        // Fleet views (#19, #20, #23) over the seeded report.
        route('fleet.show', absolute: false),
        route('fleet.view', 'advisories', false),
        route('fleet.view', 'eol', false),
        route('project.integrations.edit', absolute: false),
        route('ai-prices.edit', absolute: false),
        route('profile.edit', absolute: false),
        route('appearance.edit', absolute: false),
        route('project.edit', absolute: false),
        route('system.index', absolute: false),
        // Admin lists on the registry DataTable (app-ui wave 2).
        route('users.index', absolute: false),
        route('project-groups.index', absolute: false),
        route('groups.index', absolute: false),
        route('roles.index', absolute: false),
        route('permissions.index', absolute: false),
        route('jobs.index', absolute: false),
        route('activity-logs.index', absolute: false),
    ];

    foreach ($paths as $path) {
        foreach (['light', 'dark'] as $mode) {
            visitA11y($path, $mode)
                ->assertPresent('#main-content')
                ->assertNoAccessibilityIssues(1)
                ->assertNoJavaScriptErrors();
        }
    }
});

it('has no critical or serious axe issues on the dashboard in high contrast', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    seededA11yProject();

    visitA11y(route('dashboard', absolute: false), 'high-contrast')
        ->assertPresent('#main-content')
        ->assertNoAccessibilityIssues(1)
        ->assertNoJavaScriptErrors();
});
