<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The Exceptions tab as an inbox (#276): saved views with counts, `?exception=` opening a
 * group's drawer, and a bulk resolve of the selected rows, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('opens a group from its link and resolves the selection in bulk', function (string $scheme) {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $group = ExceptionGroup::query()->where('project_id', $project->id)->firstOrFail();

    $path = route('projects.view', [$project->slug, 'exceptions', 'exception' => $group->id], false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertPresent('nav[aria-label="Saved views"] a[aria-current="page"]:has-text("Unresolved")')
        ->assertSee('Stack')
        ->assertNoJavaScriptErrors();

    // Close the drawer, then select the row and resolve it from the selection bar.
    $page->keys('[role="dialog"]', 'Escape')
        ->wait(0.5)
        ->click('[aria-label="Select '.$group->class.'"]')
        ->assertSee('1 selected')
        ->click('[data-test="bulk-resolved"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($group->fresh()->status->value)->toBe('resolved');
})->with(['light', 'dark']);
