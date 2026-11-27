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

it('keeps the latest inbox filters when a visit outlasts the search debounce', function (string $scenario) {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $resolved = $project->exceptionGroups()->firstOrFail()->replicate();
    $resolved->forceFill(['fingerprint' => hash('sha256', 'slow-inbox-fixture'), 'status' => 'resolved', 'class' => 'SettledOrder'])->save();

    $query = $scenario === 'clear' ? ['status' => 'resolved', 'search' => 'no-match'] : [];
    $page = visit(route('projects.view', [$project->slug, 'exceptions', ...$query], false));

    // Hold the response past the debounce and let a superseding visit cancel it.
    $page->script(<<<'JS'
        () => {
            window.XMLHttpRequest = class extends XMLHttpRequest {
                responseTimer;

                send(body) {
                    const onLoad = this.onload;
                    this.onload = (event) => {
                        this.responseTimer = window.setTimeout(() => onLoad?.call(this, event), 800);
                    };
                    super.send(body);
                }

                abort() {
                    window.clearTimeout(this.responseTimer);
                    super.abort();
                }
            };
        }
        JS);

    if ($scenario === 'clear') {
        $page->click('Clear filters')
            ->assertVisible('tbody tr:has-text("SettledOrder")')
            ->assertQueryStringHas('status', 'all')
            ->assertQueryStringMissing('search');
    } else {
        $page->click('button:has-text("Resolved")')
            ->fill('input[aria-label="Search exceptions…"]', 'SettledOrder')
            ->wait(1.5)
            ->assertVisible('tbody tr:has-text("SettledOrder")')
            ->assertQueryStringHas('status', 'resolved')
            ->assertQueryStringHas('search', 'SettledOrder');
    }

    $page->assertNoJavaScriptErrors();
})->with(['clear', 'toggle']);

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

it('changes the inbox status on the server and opens a linked group outside it', function (string $scheme) {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $linkedGroup = $project->exceptionGroups()->firstOrFail();
    $resolved = $linkedGroup->replicate();
    $resolved->forceFill(['fingerprint' => hash('sha256', 'resolved-fixture'), 'status' => 'resolved', 'class' => 'SettledOrder'])->save();

    $path = route('projects.view', [$project->slug, 'exceptions'], false);
    ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertDontSee('SettledOrder')
        ->click('button:has-text("Resolved")')
        ->assertSee('SettledOrder')
        ->assertNoJavaScriptErrors();

    $linkedPath = route('projects.view', [$project->slug, 'exceptions', 'status' => 'resolved', 'exception' => $linkedGroup->id], false);
    ($scheme === 'dark' ? visit($linkedPath)->inDarkMode() : visit($linkedPath)->inLightMode())
        ->assertSee('Stack')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

it('opens an exception from the keyboard: Tab reaches the row and Enter opens it', function (string $scheme) {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $group = ExceptionGroup::query()->where('project_id', $project->id)->firstOrFail();

    // #424: the row was mouse-only. From the last header control, Tab lands on the first row.
    $path = route('projects.view', [$project->slug, 'exceptions'], false);
    $page = $scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();
    $page->script("() => [...document.querySelectorAll('thead button, thead [role=\"checkbox\"]')].at(-1).focus()");
    $page->keys('thead :focus', 'Tab');

    expect($page->script("() => document.activeElement?.matches('tbody tr:first-child')"))->toBeTrue();

    $page->keys('tbody tr:first-child', 'Enter')
        ->assertVisible('[role="dialog"]')
        ->assertSeeIn('[role="dialog"]', $group->class)
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

it('moves focus with j and k, so Enter opens the row they point at', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    $page = visit(route('projects.view', [$project->slug, 'exceptions'], false))
        ->keys('#main-content', 'j');

    expect($page->script("() => document.activeElement?.matches('tbody tr[data-active=\"true\"]')"))->toBeTrue();
});

it('closes the exception and finding drawers with a visible button on a phone', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $group = ExceptionGroup::query()->where('project_id', $project->id)->firstOrFail();

    // #454: full-screen drawers on a phone had no close control.
    visit(route('projects.view', [$project->slug, 'exceptions', 'exception' => $group->id], false))
        ->resize(390, 844)
        ->assertVisible('[role="dialog"] [data-test="drawer-close"]')
        ->click('[role="dialog"] [data-test="drawer-close"]')
        ->wait(1)
        ->assertNotPresent('[role="dialog"]')
        ->assertNoJavaScriptErrors();

    $page = visit(route('projects.view', [$project->slug, 'findings'], false))->resize(390, 844);
    $page->click('tbody tr:first-child td:nth-child(3) button')
        ->assertVisible('[role="dialog"] [data-test="drawer-close"]')
        ->click('[role="dialog"] [data-test="drawer-close"]')
        ->wait(1)
        ->assertNotPresent('[role="dialog"]')
        ->assertNoJavaScriptErrors();
});
