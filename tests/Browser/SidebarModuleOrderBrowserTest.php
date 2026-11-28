<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
});

it('reorders and saves sidebar modules using single clicks', function (string $scheme): void {
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
        ->assertSeeIn('#sidebar-module-order-status[aria-live="polite"]', 'Projects moved to position 1 of 9.')
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

it('moves a module to the last position and persists that order', function (): void {
    $moveDown = 'button[aria-label="Move Groups down"]';
    $groupsLast = '[data-id="settings"] + [data-id="groups"]:last-child';

    visit(route('appearance.sidebar.edit', absolute: false))->resize(1280, 1000)
        ->assertPresent('[data-id="groups"] + [data-id="settings"]:last-child')
        ->click($moveDown)
        ->assertPresent($groupsLast)
        ->assertSeeIn('#sidebar-module-order-status', 'Groups moved to position 9 of 9.')
        ->assertAttribute($moveDown, 'aria-disabled', 'true')
        ->click('[data-test="appearance-sidebar-settings-save"]')
        ->assertSee('Saved')
        ->refresh()
        ->assertPresent($groupsLast);
});

it('still reorders and saves sidebar modules by dragging the grip', function (): void {
    $projectsFirst = '[data-id="projects"] + [data-id="dashboard"]';
    $page = visit(route('appearance.sidebar.edit', absolute: false))->resize(1280, 1000)
        ->assertPresent('[data-id="dashboard"] + [data-id="projects"]');

    // Sortable's fallback drag checks the pointer position on a timer, so allow time between moves.
    $page->script(<<<'JS'
        async () => {
            const grip = document.querySelector('[data-id="projects"] .drag-handle');
            const target = document.querySelector('[data-id="dashboard"]');
            const sourceBounds = grip.getBoundingClientRect();
            const targetBounds = target.getBoundingClientRect();
            const horizontalPosition = sourceBounds.left + sourceBounds.width / 2;
            const startPosition = sourceBounds.top + sourceBounds.height / 2;
            const dropPosition = targetBounds.top + targetBounds.height / 4;
            const fire = (element, type, verticalPosition) => element.dispatchEvent(new PointerEvent(type, {
                bubbles: true,
                cancelable: true,
                clientX: horizontalPosition,
                clientY: verticalPosition,
                pointerId: 1,
                pointerType: 'mouse',
                isPrimary: true,
                buttons: type === 'pointerup' ? 0 : 1,
            }));
            const waitForDrag = () => new Promise((resolve) => setTimeout(resolve, 120));

            fire(grip, 'pointerdown', startPosition);
            fire(grip, 'pointermove', startPosition - 8);
            await waitForDrag();
            fire(target, 'pointermove', dropPosition);
            await waitForDrag();
            fire(target, 'pointerup', dropPosition);
        }
        JS);

    $page->assertPresent($projectsFirst)
        ->assertSeeIn('#sidebar-module-order-status', 'Projects moved to position 1 of 9.')
        ->click('[data-test="appearance-sidebar-settings-save"]')
        ->assertSee('Saved')
        ->refresh()
        ->assertPresent($projectsFirst)
        ->assertNoJavaScriptErrors();
});
