<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\MaintenanceWindow;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The registry date picker replaces the native date inputs (#428): typed in the app's language,
 * not the browser's, and submitted in the wire format the server validates.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('adds a one-off maintenance window typed in the date picker', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en'])->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    visit(route('projects.view', [$project->slug, 'alerts'], false))
        ->withLocale('it-IT')
        ->withTimezone('Europe/Rome')
        ->click('Add window')
        ->fill('#window_name', 'Upgrade')
        ->fill('Starts at', '12/31/2099 02:00')
        ->fill('Ends at', '12/31/2099 03:00')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Maintenance window added.')
        ->assertNoJavaScriptErrors();

    $window = MaintenanceWindow::query()->where('name', 'Upgrade')->sole();
    // Wall times in the window's zone (the browser's, Europe/Rome, by default), stored in UTC.
    expect($window->timezone)->toBe('Europe/Rome')
        ->and($window->starts_at?->toIso8601ZuluString())->toBe('2099-12-31T01:00:00Z')
        ->and($window->ends_at?->toIso8601ZuluString())->toBe('2099-12-31T02:00:00Z');
});

it('filters the activity log by date and clears the filter again', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en'])->assignRole(RoleEnum::SuperAdmin->value));

    $page = visit(route('activity-logs.index', absolute: false))
        ->fill('From date', '01/02/2026')
        ->wait(1)
        ->assertQueryStringHas('date_from', '2026-01-02');

    // #428 review: clearing the picker drops the bound from the URL instead of keeping it.
    $page->fill('From date', '')->click('Read-only audit trail of model changes and authentication events.')->wait(1)
        ->assertQueryStringMissing('date_from')
        ->assertNoJavaScriptErrors();
});
