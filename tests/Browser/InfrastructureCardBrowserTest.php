<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/**
 * The compact Infrastructure card of the overview (#289): the database size on one line with the
 * largest tables in the table-cell-details popover, a disk bar in the colour of its check status,
 * the open connections as a number, and the checks that did not run on one muted line, in light
 * and dark.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => now(),
        'generated_at' => now(),
        'worst_severity' => SeverityEnum::Medium,
        'medium_count' => 1,
        'report' => [
            'schemaVersion' => 1,
            'checks' => [
                [
                    'checkId' => 'laravel-disk-space', 'name' => 'Disk space', 'status' => 'warn',
                    'findings' => [['checkId' => 'laravel-disk-space', 'severity' => 'medium', 'status' => 'warn', 'subject' => 'disk', 'message' => 'The disk is 85 % full.', 'fixHint' => 'Free space.', 'details' => ['usedPercent' => 85]]],
                ],
                [
                    'checkId' => 'laravel-database-size', 'name' => 'Database size', 'status' => 'pass',
                    'findings' => [['checkId' => 'laravel-database-size', 'severity' => 'info', 'status' => 'pass', 'subject' => 'pgsql', 'message' => 'The database holds 10.11 MB.', 'fixHint' => '', 'details' => [
                        'connection' => 'pgsql',
                        'sizeMb' => 10.11,
                        'largestTables' => [['table' => 'health_reports', 'sizeMb' => 6.5], ['table' => 'findings', 'sizeMb' => 2.25], ['table' => 'jobs', 'sizeMb' => 0.08]],
                    ]]],
                ],
                [
                    'checkId' => 'laravel-database-connections', 'name' => 'Database connections', 'status' => 'warn',
                    'findings' => [['checkId' => 'laravel-database-connections', 'severity' => 'medium', 'status' => 'warn', 'subject' => 'pgsql', 'message' => 'Too many connections.', 'fixHint' => 'Look for leaks.', 'details' => ['connection' => 'pgsql', 'connectionCount' => 72]]],
                ],
                ['checkId' => 'laravel-queue-backlog', 'name' => 'Queue backlog', 'status' => 'pass', 'findings' => []],
                ['checkId' => 'laravel-redis-memory', 'name' => 'Redis memory', 'status' => 'skipped', 'findings' => []],
            ],
        ],
    ]);
    $project->forceFill(['last_report_at' => now()])->save();
});

it('keeps the infrastructure rows compact and opens the largest tables in a popover', function (string $scheme) {
    $path = route('projects.show', 'shop', false);

    $page = ($scheme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode())
        ->assertSee('10.11 MB · pgsql')
        ->assertSee('72 open connections')
        ->assertSee('Within limits')
        ->assertSee('Not configured: Redis memory, Backups')
        ->assertSee('How to turn them on')
        ->assertDontSee('health_reports')
        ->assertNoJavaScriptErrors();

    // The disk bar takes the warning colour of its badge, not the brand colour.
    expect($page->script("() => document.querySelector('[aria-label=\"Disk\"]').className.includes('[&_[data-slot=progress-indicator]]:bg-warning')"))->toBeTrue();

    $page->click('button[aria-label="Show 3 largest tables"]')
        ->assertSee('Largest tables')
        ->assertSee('health_reports')
        ->assertSee('6.5 MB')
        ->assertSee('0.08 MB')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
