<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/**
 * The short project overview (#292): a warning at the top when the report is stale, one empty
 * state for the Application section when no runtime type sends data, and a Checks card that
 * lists only the checks that did not pass, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
});

/**
 * A project without runtime data whose one report, received at the given time, has a failing
 * advisory check and a passing configuration check.
 */
function overviewProject(string $slug, CarbonInterface $receivedAt): Project
{
    $project = Project::factory()->create(['name' => Str::headline($slug), 'slug' => $slug]);
    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $receivedAt,
        'generated_at' => $receivedAt,
        'worst_severity' => SeverityEnum::High,
        'high_count' => 1,
        'report' => [
            'schemaVersion' => 1,
            'checks' => [
                [
                    'checkId' => 'composer-audit', 'name' => 'Composer audit', 'status' => 'fail',
                    'findings' => [['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => 'guzzlehttp/guzzle', 'message' => 'Known vulnerability', 'fixHint' => 'composer update guzzlehttp/guzzle']],
                ],
                ['checkId' => 'custom-check', 'name' => 'Custom check', 'status' => 'pass', 'findings' => []],
            ],
        ],
    ]);
    $project->forceFill(['last_report_at' => $receivedAt])->save();

    return $project;
}

it('warns at the top of the overview when the report is stale, and not when it is fresh', function (string $scheme) {
    overviewProject('stale-shop', now()->subDays(3));
    overviewProject('fresh-shop', now());

    $visit = fn (string $slug) => $scheme === 'dark'
        ? visit(route('projects.show', $slug, false))->inDarkMode()
        : visit(route('projects.show', $slug, false))->inLightMode();

    $visit('stale-shop')
        ->assertSee('This report is stale')
        ->assertSee('The last report arrived 3 days ago')
        ->assertDontSee('Stale · last report')
        ->assertNoJavaScriptErrors();

    $visit('fresh-shop')
        ->assertDontSee('This report is stale')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

it('shows one runtime empty state and only the failing checks on the overview', function (string $scheme) {
    overviewProject('shop', now());
    $path = route('projects.show', 'shop', false);

    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('Runtime monitoring is off')
        ->assertSee('"exceptions": { "enabled": true }')
        ->assertSee('Runtime monitoring guide')
        ->assertDontSee('View exceptions')
        ->assertDontSee('No data in this range.')
        ->assertSee('Composer audit')
        ->assertDontSee('Custom check')
        ->click('button:has-text("All checks")')
        ->assertSee('Custom check')
        ->assertNoJavaScriptErrors();

    // One empty state in the Application section instead of a card per runtime type.
    expect($page->script("() => document.querySelector('[aria-labelledby=\"application-heading\"]').querySelectorAll('[data-testid=\"page-empty-state\"]').length"))->toBe(1);
})->with(['light', 'dark']);
