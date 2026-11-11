<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Sidebar projects (#273): the star in the project header pins the project under "Projects",
 * a troubled project shows under "Needs attention", and both are links, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('pins a project from its header and lists troubled ones under Projects', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $troubled = seededA11yProject();
    $billing = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);

    $path = route('projects.show', $billing->slug, false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Needs attention')
        ->assertSee($troubled->name)
        ->click('[aria-label="Pin Billing API"]')
        ->wait(1)
        ->assertPresent('[aria-label="Unpin Billing API"][aria-pressed="true"]')
        ->assertNoJavaScriptErrors();

    expect($page->script("() => [...document.querySelectorAll('[data-sidebar=\"menu-sub\"] ul[aria-label=\"Pinned\"] a')].map((link) => link.innerText.replace(/\s+/g, ' ').trim())"))
        ->toBe(['Healthy Billing API'])
        ->and($admin->pinnedProjects()->pluck('slug')->all())->toBe(['billing-api']);

    $page->click('[data-sidebar="menu-sub"] a:has-text("'.$troubled->name.'")')
        ->wait(1)
        ->assertPathIs(route('projects.show', $troubled->slug, false))
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
