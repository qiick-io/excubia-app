<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The grouped project tabs (#275): the Issues group carries the open count, its menu lists
 * Exceptions and Findings, and picking one opens that view's own URL, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('opens a view from its group menu and keeps the group active', function (string $scheme) {
    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    $this->actingAs($admin);
    $project = seededA11yProject();

    $path = route('projects.show', $project->slug, false);
    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Issues')
        ->click('[role="tab"]:has-text("Issues")')
        ->click('[role="menuitem"]:has-text("Exceptions")')
        ->wait(1)
        ->assertPathIs(route('projects.view', [$project->slug, 'exceptions'], false))
        ->assertSee('Issues: Exceptions')
        ->assertNoJavaScriptErrors();

    $openExceptions = $project->exceptionGroups()->where('status', 'open')->count();
    $activeTab = $page->script("() => document.querySelector('[role=\"tab\"][data-state=\"active\"], [role=\"tab\"][aria-current=\"page\"]')?.innerText ?? ''");

    // #426: the trigger names the open view and shows its count, not findings + exceptions.
    expect($activeTab)->toContain('Issues')
        ->and(preg_match('/Issues: Exceptions\s*(\d*)/', $activeTab, $match))->toBe(1)
        ->and((int) $match[1])->toBe($openExceptions);
})->with(['light', 'dark']);

it('pages through the report history in the project tab', function (string $scheme) {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    foreach (range(1, 21) as $number) {
        $project->healthReports()->create([
            'batch_id' => (string) Str::uuid(),
            'received_at' => now()->addMinutes($number),
            'generated_at' => now(),
            'report' => ['excubiaVersion' => 'history-fixture-'.$number, 'checks' => []],
        ]);
    }

    $path = route('projects.view', [$project->slug, 'history'], false);
    ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('history-fixture-21')
        ->click('button[aria-label="Go to page 2"]')
        ->assertSee('history-fixture-1')
        ->assertDontSee('history-fixture-21')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
