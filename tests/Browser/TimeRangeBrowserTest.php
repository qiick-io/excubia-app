<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The custom time range (#247): picked on the registry date range picker with times, kept in the
 * URL as `from` / `to`, and cleared back to the default preset.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('picks a custom range with times and clears it', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    $page = visit(route('projects.view', [$project, 'runtime'], false))
        ->click('button[aria-label^="Custom range"]')
        // Today twice: a one-day range, from 00:00 to now (the end is clamped).
        ->click('.rdp-today button')
        ->click('.rdp-today button')
        ->fill('#date-range-picker-start-time', '00:00')
        ->click('Apply')
        ->assertQueryStringHas('from')
        ->assertQueryStringHas('to')
        ->assertSee('Requests over time')
        ->assertNoJavaScriptErrors();

    $page->click('button[aria-label*="Custom range"]')
        ->click('Clear')
        ->assertQueryStringMissing('from')
        ->assertSee('Last 24 hours')
        ->assertNoJavaScriptErrors();
});
