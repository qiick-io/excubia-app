<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * The admin lists on the registry DataTable (app-ui #28): header sorting is done by the server
 * through the URL, and activity log rows expand in place.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('sorts the users list from the column header', function () {
    $this->actingAs(User::factory()->create(['first_name' => 'Zed', 'last_name' => 'Admin'])->assignRole(RoleEnum::SuperAdmin->value));
    User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    visit(route('users.index', absolute: false))
        ->click('thead button:has-text("Name")')
        ->click('Sort ascending')
        ->assertQueryStringHas('sort', 'name')
        ->assertQueryStringHas('direction', 'asc')
        ->assertSeeIn('tbody tr:first-child', 'Ada Lovelace')
        ->assertNoJavaScriptErrors();
});

it('expands an activity log row with its details', function () {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    activity()->causedBy($admin)->log('Browser check');
    $this->actingAs($admin);

    visit(route('activity-logs.index', absolute: false))
        ->assertSee('Browser check')
        ->assertDontSee('No field changes recorded.')
        ->click('tbody tr:first-child button')
        ->assertSee('No field changes recorded.')
        ->assertNoJavaScriptErrors();
});

it('selects findings with the registry select column and the toolbar counts them', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    visit(route('projects.view', [$project, 'findings'], false))
        ->click('tbody tr:first-child [role="checkbox"]')
        ->assertSee('1 selected')
        ->click('thead [role="checkbox"]')
        ->assertDontSee('1 selected')
        ->assertNoJavaScriptErrors();
});

it('creates a project group with the project picker and narrows the dashboard to it', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    Project::factory()->create(['name' => 'Acme Shop']);
    Project::factory()->create(['name' => 'Blog']);

    visit(route('project-groups.index', absolute: false))
        ->click('New project group')
        ->fill('#project_group_name', 'Acme')
        ->click('[role="combobox"][aria-label="Projects"]')
        ->click('[role="option"]:has-text("Acme Shop")')
        ->click('#project_group_description')
        ->click('button[type="submit"]')
        ->waitForText('Project group created.')
        // The drawer closes with an animation; the list behind it is inert until then.
        ->wait(1)
        ->assertSeeIn('tbody tr:first-child', 'Acme Shop')
        ->assertNoJavaScriptErrors();

    $acme = ProjectGroup::query()->where('name', 'Acme')->sole();

    visit(route('dashboard', ['group' => $acme->id], false))
        ->assertSee('All projects (1)')
        ->assertNoJavaScriptErrors();
});
