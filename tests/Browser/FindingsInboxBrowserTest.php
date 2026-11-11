<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The Findings tab as an inbox (#276): saved views with counts, and "Assign to me" for the
 * selected rows, after which "Assigned to me" lists them, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('assigns the selected findings to me and finds them under Assigned to me', function (string $scheme) {
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin'])->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = seededA11yProject();

    $path = route('projects.view', [$project->slug, 'findings'], false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertPresent('nav[aria-label="Saved views"] a[aria-current="page"]:has-text("Open")')
        ->click('tbody tr:first-child [role="checkbox"]')
        ->click('[data-test="bulk-assign-me"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($project->findings()->where('assigned_user_id', $admin->id)->count())->toBe(1);

    $page->click('nav[aria-label="Saved views"] a:has-text("Assigned to me")')
        ->wait(1)
        ->assertQueryStringHas('assigned', 'me')
        ->assertNoJavaScriptErrors();

    expect($page->script("() => document.querySelectorAll('tbody tr').length"))->toBe(1);
})->with(['light', 'dark']);
