<?php

declare(strict_types=1);

use App\Actions\StorePushedRuntimeAction;
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

it('shows the Board and the labelled composition counts in either theme', function (string $scheme): void {
    $today = now();
    $project = overviewProject('checkout-api', $today->subDays(31));
    $baseline = $project->healthReports()->sole();
    $report = $baseline->report;
    $baseline->update([
        'high_count' => 6, 'medium_count' => 12, 'low_count' => 2,
        'report' => [...$report, 'checks' => [...$report['checks'],
            ['checkId' => 'baseline-check-2', 'status' => 'pass', 'findings' => []],
            ['checkId' => 'baseline-check-3', 'status' => 'pass', 'findings' => []],
        ]],
    ]);
    $requestSamples = [];

    for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
        $recorded = $today->subDays($daysAgo)->subMinute()->startOfMinute();
        $passChecks = array_map(
            fn (int $number): array => ['checkId' => 'check-'.$number, 'status' => 'pass', 'findings' => []],
            range(1, 4 + $daysAgo % 7),
        );
        $project->healthReports()->create([
            'batch_id' => Str::uuid()->toString(),
            'received_at' => $recorded,
            'generated_at' => $recorded,
            'high_count' => 3 + $daysAgo % 5,
            'medium_count' => 12 + $daysAgo % 7,
            'low_count' => 4 + $daysAgo % 4,
            'worst_severity' => SeverityEnum::High,
            'report' => [
                ...$report,
                'checks' => [$report['checks'][0], ...$passChecks],
                'project' => ['php' => ['version' => '8.5.0', 'securitySupportEndsOn' => $today->addYear()->toDateString()]],
            ],
        ]);
        $requests = 350 + $daysAgo % 6 * 73;
        $requestSamples[] = [
            'type' => 'request', 'bucket' => $recorded->toIso8601ZuluString(), 'sampleRate' => 1,
            'route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx',
            'count' => $requests, 'errorCount' => 0, 'totalMs' => $requests * (72 + $daysAgo % 5 * 4), 'maxMs' => 210,
        ];
    }

    $project->forceFill(['last_report_at' => $today])->save();
    app(StorePushedRuntimeAction::class)->execute($project, Str::uuid()->toString(), $requestSamples);

    foreach ([36, 19, 7] as $daysAgo) {
        $seen = $today->subDays($daysAgo);
        $exception = $project->exceptionGroups()->create([
            'fingerprint' => hash('sha256', (string) $daysAgo), 'environment' => 'production',
            'class' => 'RuntimeException', 'message' => 'Payment confirmation timed out',
            'frames' => [], 'previous' => [], 'context_kind' => 'request', 'context_name' => 'checkout.store',
            'count' => 3, 'first_seen_at' => $seen, 'last_seen_at' => $seen, 'status' => 'open',
        ]);
        $exception->openChanges()->create(['project_id' => $project->id, 'occurred_at' => $seen, 'delta' => 1]);
    }

    $path = route('projects.show', $project, false);
    $page = $scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();
    $page->resize(1600, 1000)
        ->assertSee('Checks passing')
        ->assertSee('Runtimes supported')
        ->assertSee('Open exceptions')
        ->assertSee('Open findings by severity')
        ->assertSee('Check outcomes')
        ->assertScript("() => document.querySelectorAll('[data-slot=\"chart\"][aria-hidden=\"true\"] path.recharts-area-curve').length", 4)
        ->assertScript("() => document.querySelectorAll('ul[aria-label=\"Open findings by severity\"] li').length", 5)
        ->assertScript("() => document.querySelectorAll('ul[aria-label=\"Check outcomes\"] li').length", 4)
        ->assertNoJavaScriptErrors()
        ->screenshot(fullPage: false, filename: "overview-board-{$scheme}")
        ->resize(390, 844)
        ->assertScript("() => { const board = document.querySelector('section[aria-label=\"Key metrics, 30-day trends\"]'); return board.scrollWidth <= board.clientWidth; }", true);

    $dashboard = route('dashboard', absolute: false);
    $fleet = $scheme === 'dark' ? visit($dashboard)->inDarkMode() : visit($dashboard)->inLightMode();
    $fleet->assertSee('Findings over time')
        ->assertScript("() => Array.from(document.querySelectorAll('button[aria-pressed]')).some((button) => button.textContent.trim() === 'Open findings 19')", true)
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
