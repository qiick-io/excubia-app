<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/**
 * Repeated findings (#291): the packages that share a check and a message are one row of
 * "Needs attention" on the project overview and of "Top issues" on the dashboard, which expands
 * to list them and links the blockers to the upgrade plan in Fleet, in light and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $blocker = fn (string $package): array => [
        'checkId' => 'laravel-next-blocker', 'severity' => 'medium', 'status' => 'fail', 'subject' => $package,
        'message' => 'Latest release does not allow Laravel 14.', 'fixHint' => 'Watch, replace or fork.',
        'details' => ['package' => $package, 'lockedVersion' => '1.0.0', 'targetMajor' => 14],
    ];
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => now(),
        'generated_at' => now(),
        'worst_severity' => SeverityEnum::High,
        'high_count' => 1,
        'medium_count' => 3,
        'report' => [
            'schemaVersion' => 1,
            'checks' => [
                [
                    'checkId' => 'composer-audit', 'name' => 'Composer audit', 'status' => 'fail',
                    'findings' => [['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => 'guzzlehttp/guzzle', 'message' => 'Known vulnerability', 'fixHint' => 'composer update guzzlehttp/guzzle']],
                ],
                [
                    'checkId' => 'laravel-next-blocker', 'name' => 'Next-major blockers', 'status' => 'fail',
                    'findings' => array_map($blocker, ['spatie/one', 'spatie/two', 'spatie/three']),
                ],
            ],
        ],
    ]);
    $project->forceFill(['last_report_at' => now()])->save();
});

it('shows the blocking packages as one expandable row on the project overview and the dashboard', function (string $scheme) {
    foreach ([route('projects.show', 'shop', false), route('dashboard', [], false)] as $path) {
        $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
            ->assertSee('3 packages block Laravel 14')
            ->assertSee('Upgrade plan in Fleet')
            ->assertDontSee('spatie/two')
            ->click('button:has-text("Show all 3")')
            ->assertSee('spatie/two')
            ->assertNoJavaScriptErrors();

        // Two rows: the advisory and the group of blockers.
        expect($page->script("() => [...document.querySelectorAll('tbody')].find((body) => body.innerText.includes('Laravel 14'))?.querySelectorAll(':scope > tr').length"))->toBe(2);
    }
})->with(['light', 'dark']);
