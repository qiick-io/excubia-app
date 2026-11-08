<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
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
