<?php

declare(strict_types=1);

use App\Enums\AlertEventEnum;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Services\AlertDispatcherService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

/**
 * Axe smoke for the main pages (#112), as in externa-core: fails on critical or serious
 * violations (`assertNoAccessibilityIssues(1)`) in light and dark, plus one high-contrast pass.
 * Runs in the `Accessibility` CI job, not in `php artisan test`.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/**
 * Visits a page with the Playwright colour scheme of the mode, then forces the theme classes so
 * the tokens of the mode apply whatever the stored appearance is.
 *
 * @param  'light'|'dark'|'high-contrast'  $mode
 */
function visitA11y(string $path, string $mode): mixed
{
    $page = $mode === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();
    $isDark = $mode === 'dark' ? 'true' : 'false';
    $isHighContrast = $mode === 'high-contrast' ? 'true' : 'false';

    return $page->assertScript(
        "() => { const root = document.documentElement; root.classList.toggle('dark', {$isDark}); root.classList.toggle('high-contrast', {$isHighContrast}); root.style.colorScheme = {$isDark} ? 'dark' : 'light'; return true; }",
        true,
    );
}

/**
 * A project with one report (every core check id), runtime rows, an exception group, scheduled
 * tasks and alerts with a mute and a maintenance window, so every tab renders content.
 */
function seededA11yProject(): Project
{
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $now = now();
    $coreReport = json_decode((string) file_get_contents(base_path('tests/Fixtures/core/report-every-check.json')), true, flags: JSON_THROW_ON_ERROR);

    $project->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $now,
        'generated_at' => $now,
        'worst_severity' => SeverityEnum::High,
        'high_count' => 1,
        // Every core check id with its details (#109) and the license inventory (#108).
        'report' => [
            ...$coreReport,
            'checks' => [[
                'checkId' => 'composer-audit',
                'name' => 'Composer audit',
                'status' => 'fail',
                'findings' => [['checkId' => 'composer-audit', 'severity' => 'high', 'status' => 'fail', 'subject' => 'guzzlehttp/guzzle', 'message' => 'Known vulnerability', 'fixHint' => 'composer update guzzlehttp/guzzle']],
            ], ...$coreReport['checks']],
            'upgradePlan' => [['stepNumber' => 1, 'message' => 'composer update guzzlehttp/guzzle']],
        ],
    ]);
    $project->forceFill(['last_report_at' => $now])->save();

    RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => 'request',
        'bucket' => $now->copy()->subMinutes(5)->startOfMinute(),
        'sample_rate' => 1,
        'dimension_key' => str_repeat('b', 64),
        'dimensions' => ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx'],
        'count' => 90,
        'error_count' => 2,
        'total_ms' => 9000,
        'max_ms' => 800,
    ]);

    // Live health strip of the overview (#115): a failing check and a stale server.
    foreach ([['web-1', 'laravel-database', 'fail', 1], ['worker-1', 'laravel-database', 'pass', 20], ['web-1', 'laravel-cache', 'pass', 1]] as [$server, $checkId, $status, $minutesAgo]) {
        RuntimeAggregate::query()->create([
            'project_id' => $project->id,
            'type' => 'health',
            'bucket' => $now->copy()->subMinutes($minutesAgo)->startOfMinute(),
            'sample_rate' => 1,
            'dimension_key' => hash('sha256', $server.$checkId),
            'dimensions' => ['server' => $server, 'checkId' => $checkId],
            'count' => 1,
            'error_count' => $status === 'fail' ? 1 : 0,
            'metrics' => ['status' => $status, 'severity' => $status === 'fail' ? 'critical' : 'info', 'findingCount' => $status === 'fail' ? 1 : 0, 'fingerprints' => []],
        ]);
    }

    ExceptionGroup::query()->create([
        'project_id' => $project->id,
        'fingerprint' => str_repeat('a', 64),
        'environment' => 'production',
        'class' => 'RuntimeException',
        'message' => 'Payment failed',
        'frames' => [['file' => 'app/Actions/PayAction.php', 'line' => 12, 'function' => 'App\\Actions\\PayAction::execute', 'inApp' => true]],
        'previous' => [],
        'context_kind' => 'request',
        'context_name' => 'checkout.store',
        'count' => 4,
        'first_seen' => $now->copy()->subHour(),
        'last_seen' => $now,
        'status' => 'open',
    ]);

    // The heartbeat missed shows the "scheduler down" alert on the Schedule tab (#110).
    foreach ([[ScheduledTask::HEARTBEAT_TASK, '* * * * *', 'missed'], ['artisan reports:send', '0 3 * * *', 'scheduler_down']] as $position => [$task, $expression, $status]) {
        ScheduledTask::query()->create([
            'project_id' => $project->id,
            'task_id' => hash('sha256', $task."\0".$expression),
            'task' => $task,
            'expression' => $expression,
            'timezone' => 'Europe/Rome',
            'environments' => ['production'],
            'position' => $position,
        ])->forceFill(['status' => $status, 'last_run_at' => $now->copy()->subHour(), 'last_outcome' => 'finished', 'next_due_at' => $now->copy()->addHour()])->save();
    }

    // Alerts tab (#114): one alert in the history, a muted issue and a weekly window.
    app(AlertDispatcherService::class)->dispatch(AlertEventEnum::ScheduleFailed, $project, 'artisan reports:send (0 3 * * *): failed', subject: 'task');
    AlertMute::query()->create(['project_id' => $project->id, 'event' => 'schedule.failed', 'subject' => 'task', 'label' => 'artisan reports:send', 'until' => $now->copy()->addDay()]);
    MaintenanceWindow::query()->create(['project_id' => $project->id, 'name' => 'Nightly deploy', 'recurrence' => 'weekly', 'weekdays' => [1, 3], 'start_time' => '02:00', 'duration_minutes' => 30, 'timezone' => 'Europe/Rome']);

    return $project;
}

it('has no critical or serious axe issues on login in light, dark and high contrast', function () {
    foreach (['light', 'dark', 'high-contrast'] as $mode) {
        visitA11y('/login', $mode)
            ->assertPresent('#main-content')
            ->assertNoAccessibilityIssues(1)
            ->assertNoJavaScriptErrors();
    }
});

it('has no critical or serious axe issues on the first-run wizard in light, dark and high contrast', function () {
    // Admin step: a fresh install without users (#116).
    foreach (['light', 'dark', 'high-contrast'] as $mode) {
        visitA11y('/setup', $mode)
            ->assertPresent('#main-content')
            ->assertNoAccessibilityIssues(1)
            ->assertNoJavaScriptErrors();
    }

    // Project step: the new admin, no project yet.
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));

    foreach (['light', 'dark'] as $mode) {
        visitA11y('/setup', $mode)
            ->assertSee('Project name')
            ->assertNoAccessibilityIssues(1)
            ->assertNoJavaScriptErrors();
    }
});

it('has no critical or serious axe issues on the main authenticated pages in light and dark', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    $project = seededA11yProject();

    $paths = [
        route('dashboard', absolute: false),
        route('projects.index', absolute: false),
        route('projects.show', $project, false),
        route('projects.view', [$project, 'findings'], false).'?finding='.rawurlencode('laravel-backups|s3|'),
        route('projects.view', [$project, 'licenses'], false),
        route('projects.view', [$project, 'runtime'], false),
        route('projects.view', [$project, 'exceptions'], false),
        route('projects.view', [$project, 'schedule'], false),
        route('projects.view', [$project, 'alerts'], false),
        route('project.integrations.edit', absolute: false),
        route('profile.edit', absolute: false),
        route('appearance.edit', absolute: false),
        route('project.edit', absolute: false),
        route('system.index', absolute: false),
    ];

    foreach ($paths as $path) {
        foreach (['light', 'dark'] as $mode) {
            visitA11y($path, $mode)
                ->assertPresent('#main-content')
                ->assertNoAccessibilityIssues(1)
                ->assertNoJavaScriptErrors();
        }
    }
});

it('has no critical or serious axe issues on the dashboard in high contrast', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value));
    seededA11yProject();

    visitA11y(route('dashboard', absolute: false), 'high-contrast')
        ->assertPresent('#main-content')
        ->assertNoAccessibilityIssues(1)
        ->assertNoJavaScriptErrors();
});
