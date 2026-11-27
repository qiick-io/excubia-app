<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Who is on an exception group or a finding (#254): picked in its drawer, shown in the list, and
 * found again with "Assigned to me"; assigned from a row's actions, which shows the avatar in the
 * assignee column (#332). In light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('assigns an exception group from its drawer and filters the list to mine', function (string $scheme) {
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = seededA11yProject();
    $group = ExceptionGroup::query()->where('project_id', $project->id)->firstOrFail();

    $path = route('projects.view', [$project, 'exceptions'], false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->click($group->class)
        ->click('#exception-assignee-'.$group->id)
        ->click('[role="option"]:has-text("Ada Lovelace")')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($group->fresh()->assigned_user_id)->toBe($admin->id);

    // The list on a fresh load: the assignee column, then the filter.
    $page = $scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();
    $page->assertSee($group->class);

    // The assignee cell shows the avatar's initials next to the name (#332).
    expect($page->script("() => [...document.querySelectorAll('tbody [data-test=\"assignee-cell\"] > span:last-child')].map((cell) => cell.innerText.trim())"))
        ->toContain('Ada Lovelace');

    $page->click('Assigned to me')
        ->assertSee($group->class)
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

it('assigns an exception group to me from its row and shows my avatar', function (string $scheme) {
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = seededA11yProject();
    $group = ExceptionGroup::query()->where('project_id', $project->id)->firstOrFail();

    $path = route('projects.view', [$project, 'exceptions'], false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Unassigned')
        ->click('[data-test="row-assign-me"]')
        ->wait(1)
        ->assertPresent('[data-test="assignee-cell"]:has-text("Ada Lovelace")')
        ->assertSeeIn('[data-test="assignee-cell"]', 'AL')
        ->assertNoJavaScriptErrors();

    expect($group->fresh()->assigned_user_id)->toBe($admin->id)
        // The row stays a row: the action did not open the drawer.
        ->and($page->script("() => document.querySelector('[role=\"dialog\"]') === null"))->toBeTrue();
})->with(['light', 'dark']);

it('assigns a finding to someone from the row menu and filters the list by assignee', function (string $scheme) {
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->assignRole(RoleEnum::SuperAdmin->value);
    User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper'])->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = seededA11yProject();

    $path = route('projects.view', [$project->slug, 'findings'], false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->click('tbody tr:first-child [data-test="row-assign"]')
        ->click('[role="menuitemradio"]:has-text("Grace Hopper")')
        ->wait(1)
        ->assertPresent('[data-test="assignee-cell"]:has-text("Grace Hopper")')
        ->assertNoJavaScriptErrors();

    expect($project->findings()->whereHas('assignee', fn ($query) => $query->where('first_name', 'Grace'))->count())->toBe(1);

    $page->click('[data-slot="popover-trigger"]:has-text("Assignee")')
        ->click('[role="option"]:has-text("Grace Hopper")')
        ->wait(0.5)
        ->assertQueryStringHas('assignee')
        ->assertNoJavaScriptErrors();

    expect($page->script("() => document.querySelectorAll('tbody tr').length"))->toBe(1);
})->with(['light', 'dark']);
