<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Keyboard triage and list preferences (#277): `j` marks a row, `x` selects it, `e` resolves
 * it; the user menu turns single-key shortcuts off and tables compact, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('triages the exceptions list with the keyboard', function (string $scheme) {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();
    $group = ExceptionGroup::query()->where('project_id', $project->id)->firstOrFail();

    $path = route('projects.view', [$project->slug, 'exceptions'], false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->keys('#main-content', 'j')
        ->assertPresent('tbody tr[data-active="true"]')
        ->keys('#main-content', 'x')
        ->assertSee('1 selected')
        ->keys('#main-content', 'e')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($group->fresh()->status->value)->toBe('resolved');
})->with(['light', 'dark']);

it('turns single-key shortcuts off and tables compact in the profile settings', function () {
    $this->actingAs(User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin'])->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    // #433: the preferences moved from the user menu to Settings, with a description each.
    $page = visit(route('profile.edit', absolute: false))
        ->assertSee('Denser table rows')
        ->assertScript('() => document.documentElement.dataset.density', 'comfortable')
        ->click('[data-test="preference-compact-tables"]')
        ->click('[data-test="preference-single-key-shortcuts"]')
        ->assertScript('() => document.documentElement.dataset.density', 'compact')
        ->assertNoJavaScriptErrors();

    $page->navigate(route('projects.view', [$project->slug, 'exceptions'], false))
        ->assertScript('() => document.documentElement.dataset.density', 'compact')
        ->keys('#main-content', 'j')
        ->assertNotPresent('tbody tr[data-active="true"]')
        ->assertNoJavaScriptErrors();

    // The preference survives a reload, stamped before paint from its cookie.
    $page->navigate(route('projects.view', [$project->slug, 'exceptions'], false))
        ->assertScript('() => document.documentElement.dataset.density', 'compact');
});
