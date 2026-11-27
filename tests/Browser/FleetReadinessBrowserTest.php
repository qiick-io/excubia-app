<?php

declare(strict_types=1);

use App\Actions\SyncFindingLifecycleAction;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/**
 * Fleet upgrade readiness (#290): the blocking packages of a project stay on one line as a count
 * and the first package, and the full list (name, version, pre-release note) opens from the
 * keyboard in the table-cell-details popover, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $blocker = fn (string $package, string $version, string $status): array => [
        'checkId' => 'laravel-next-blocker',
        'severity' => $status === 'fail' ? 'medium' : 'low',
        'status' => $status,
        'subject' => $package,
        'message' => $package.' does not allow Laravel 14.',
        'fixHint' => 'Wait for a release that allows Laravel 14.',
        'details' => ['package' => $package, 'lockedVersion' => $version, 'targetMajor' => 14],
    ];

    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $report = $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => now(),
        'generated_at' => now(),
        'worst_severity' => SeverityEnum::Medium,
        'medium_count' => 2,
        'report' => [
            'schemaVersion' => 1,
            'project' => ['laravel' => ['version' => '13.2.0']],
            'checks' => [[
                'checkId' => 'laravel-next-blocker', 'name' => 'Laravel next major blockers', 'status' => 'fail',
                'findings' => [
                    $blocker('larastan/larastan', '3.4.0', 'fail'),
                    $blocker('spatie/laravel-ray', '1.40.0', 'fail'),
                    $blocker('acme/beta-only', '2.0.0', 'warn'),
                ],
            ]],
        ],
    ]);
    $project->forceFill(['last_report_at' => now()])->save();
    app(SyncFindingLifecycleAction::class)->execute($project, $report);
});

it('shows the blocking packages as a count and opens the list from the keyboard', function (string $scheme) {
    $path = route('fleet.show', absolute: false);
    $trigger = 'button[aria-label="Show 3 packages blocking the upgrade"]';

    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('3 packages · larastan/larastan +2')
        ->assertDontSee('spatie/laravel-ray')
        ->assertNoJavaScriptErrors();

    // Tab from the project link reaches the details button: nothing else sits in between.
    $page->script("() => document.querySelector('td a[href$=\"/projects/shop\"]').focus()");
    $page->keys('td a[href$="/projects/shop"]', 'Tab');
    expect($page->script("() => document.activeElement.getAttribute('aria-label')"))->toBe('Show 3 packages blocking the upgrade');

    $page->keys($trigger, 'Enter')
        ->assertSee('Blocking packages')
        ->assertSee('spatie/laravel-ray 1.40.0')
        ->assertSee('acme/beta-only 2.0.0 · Pre-release only')
        ->assertNoJavaScriptErrors();

    // Escape closes the popover and gives the focus back to the button.
    $page->keys('[data-slot="popover-content"]', 'Escape')
        ->assertDontSee('spatie/laravel-ray');
    expect($page->script("() => document.activeElement.getAttribute('aria-label')"))->toBe('Show 3 packages blocking the upgrade');
})->with(['light', 'dark']);
