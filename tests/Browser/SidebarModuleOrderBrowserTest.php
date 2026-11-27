<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

it('reorders and saves sidebar modules using single clicks', function (string $scheme): void {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $path = route('appearance.sidebar.edit', absolute: false);
    $moveUp = 'button[aria-label="Move Projects up"]';
    $moveDown = 'button[aria-label="Move Projects down"]';
    $dashboardBeforeProjects = '[data-id="dashboard"] + [data-id="projects"]';
    $projectsBeforeDashboard = '[data-id="projects"] + [data-id="dashboard"]';

    $page = $scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();
    $page->resize(390, 844)
        ->assertPresent($dashboardBeforeProjects)
        ->click($moveUp)
        ->assertPresent($projectsBeforeDashboard)
        ->assertAttribute($moveUp, 'aria-disabled', 'true');

    expect($page->script("() => document.activeElement?.getAttribute('aria-label')"))->toBe('Move Projects up');

    $page->click($moveDown)
        ->assertPresent($dashboardBeforeProjects)
        ->click($moveUp)
        ->click('[data-test="appearance-sidebar-settings-save"]')
        ->assertSee('Saved')
        ->refresh()
        ->assertPresent($projectsBeforeDashboard)
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
