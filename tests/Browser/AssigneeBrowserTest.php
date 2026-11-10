<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Who is on an exception group (#254): picked in its drawer, shown in the list, and found again
 * with "Assigned to me", in light and dark.
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

    expect($page->script("() => [...document.querySelectorAll('tbody td')].map((cell) => cell.innerText.trim())"))
        ->toContain('Ada Lovelace');

    $page->click('Assigned to me')
        ->assertSee($group->class)
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
