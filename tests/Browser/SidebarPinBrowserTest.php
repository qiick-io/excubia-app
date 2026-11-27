<?php

declare(strict_types=1);

use App\Actions\PinProjectAction;
use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Pinning from the sidebar (#294): the star on each project row is reached with Tab, Enter
 * toggles it and focus follows the row to its new block; the row's right-click menu has the
 * same action, and the eight-pin limit answers with an error toast. In light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/** The accessible label of the focused element. */
function sidebarPinFocusedLabel(mixed $page): ?string
{
    return $page->script("() => document.activeElement?.getAttribute('aria-label') ?? null");
}

it('pins and unpins a project from the sidebar with the keyboard', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    seededA11yProject();
    $rowLink = '[data-sidebar="menu-sub"] a[href$="/projects/shop"]';

    $path = route('dashboard', absolute: false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Needs attention')
        // The empty Pinned block takes no space: the hint moved to the projects list.
        ->assertDontSee('Pinned')
        ->assertDontSee('Pin a project with the star')
        ->assertNoJavaScriptErrors();

    // Tab from the record reaches its star.
    $page->script("() => document.querySelector('{$rowLink}').focus()");
    $page->keys($rowLink, 'Tab');
    expect(sidebarPinFocusedLabel($page))->toBe('Pin Shop');

    // Enter pins it: the row moves to Pinned and focus moves with it to the same star.
    $page->keys('[aria-label="Pin Shop"]', 'Enter')
        ->wait(1)
        ->assertPresent('ul[aria-label="Pinned"] [aria-label="Unpin Shop"][aria-pressed="true"]')
        ->assertNoJavaScriptErrors();
    expect(sidebarPinFocusedLabel($page))->toBe('Unpin Shop')
        ->and($admin->pinnedProjects()->pluck('slug')->all())->toBe(['shop']);

    // Enter again unpins it, back under Needs attention, with focus on its star.
    $page->keys('[aria-label="Unpin Shop"]', 'Enter')
        ->wait(1)
        ->assertPresent('ul[aria-label="Needs attention"] [aria-label="Pin Shop"][aria-pressed="false"]')
        ->assertNoJavaScriptErrors();
    expect(sidebarPinFocusedLabel($page))->toBe('Pin Shop')
        ->and($admin->pinnedProjects()->count())->toBe(0);
})->with(['light', 'dark']);

it('pins and unpins from the row menu, and explains pinning on the projects list', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    seededA11yProject();
    $rowLink = '[data-sidebar="menu-sub"] a[href$="/projects/shop"]';

    $path = route('projects.index', absolute: false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Pin a project with the star to keep it in the sidebar.')
        ->rightClick($rowLink)
        ->click('[role="menuitem"]:has-text("Pin Shop")')
        ->wait(1)
        ->assertPresent('ul[aria-label="Pinned"] [aria-label="Unpin Shop"]')
        // With a pin, the list no longer repeats the hint.
        ->assertDontSee('Pin a project with the star to keep it in the sidebar.')
        ->assertNoJavaScriptErrors();
    expect($admin->pinnedProjects()->pluck('slug')->all())->toBe(['shop']);

    $page->rightClick($rowLink)
        ->click('[role="menuitem"]:has-text("Unpin Shop")')
        ->wait(1)
        ->assertPresent('ul[aria-label="Needs attention"] [aria-label="Pin Shop"]')
        ->assertNoJavaScriptErrors();
    expect($admin->pinnedProjects()->count())->toBe(0);
})->with(['light', 'dark']);

it('shows the eight-pin limit as an error toast', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    seededA11yProject();
    Project::factory()->count(8)->create()->each(
        fn (Project $project) => app(PinProjectAction::class)->execute($admin, $project),
    );

    visit(route('dashboard', absolute: false))
        ->keys('[aria-label="Pin Shop"]', 'Enter')
        ->wait(1)
        ->assertSee('Unpin a project first: the sidebar keeps 8.')
        ->assertNoJavaScriptErrors();

    expect($admin->pinnedProjects()->count())->toBe(8);
});
