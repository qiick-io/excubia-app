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
        ->click('Add window')
        ->fill('#window_name', 'Upgrade')
        ->fill('Starts at', '12/31/2099 02:00')
        ->fill('Ends at', '12/31/2099 03:00')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Maintenance window added.')
        ->assertNoJavaScriptErrors();

    $window = MaintenanceWindow::query()->where('name', 'Upgrade')->sole();
    expect($window->recurrence)->toBe('once')
        ->and($window->starts_at?->year)->toBe(2099);
});
