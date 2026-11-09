<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The custom time range (#247): picked on the registry date range picker with times, kept in the
 * URL as `from` / `to`, and cleared back to the default preset; or dragged across a time chart
 * (#248).
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

it('zooms into the span dragged across the requests chart (#248)', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    // React listens to native mouse events. Recharts reads the hovered bucket on a throttled
    // mousemove, so the pointer moves onto the chart before it is pressed, as a real one does.
    $drag = <<<'JS'
        async () => {
            const chart = document.querySelector('[aria-label^="Stacked bar chart of requests"] .recharts-wrapper');
            const rect = chart.getBoundingClientRect();
            const fire = async (type, share) => {
                chart.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: rect.left + rect.width * share, clientY: rect.top + rect.height * 0.4 }));
                await new Promise((resolve) => setTimeout(resolve, 120));
            };
            await fire('mousemove', 0.5);
            await fire('mousedown', 0.5);
            await fire('mousemove', 0.7);
            await fire('mousemove', 0.9);
            await fire('mouseup', 0.9);
        }
        JS;

    $page = visit(route('projects.view', [$project, 'runtime'], false))
        ->assertSee('Requests over time');

    $page->script($drag);

    $page->wait(1)
        ->assertQueryStringHas('from')
        ->assertQueryStringHas('to')
        ->assertDontSee('Last 24 hours')
        ->assertSee('Requests over time')
        ->assertNoJavaScriptErrors();
});
