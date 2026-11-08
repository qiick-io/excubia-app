<?php

declare(strict_types=1);

use App\Actions\SyncFindingLifecycleAction;
use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Models\AlertMute;
use App\Models\ExceptionGroup;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\ScheduledTask;
use App\Services\AlertDispatcherService;
use Illuminate\Support\Str;

/*
 * Shared by the browser tests: the axe smoke (#112) and the visual QA screenshots (#194).
 */

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

    $report = $project->healthReports()->create([
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
    // The Findings tab lists the finding lifecycle (#18).
    app(SyncFindingLifecycleAction::class)->execute($project, $report);
    // A dismissed advisory and blocker, so the Fleet pages list them apart (#148).
    $project->findings()->whereIn('check_id', ['npm-advisories', 'laravel-next-blocker'])->update([
        'dismissed_at' => $now,
        'dismissed_until' => $now->copy()->addMonth()->toDateString(),
        'dismissed_reason' => 'Not reachable from user input',
    ]);

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

    // AI tab (#129): an agent run with tokens and one tool call.
    foreach ([['ai', ['operation' => 'agent', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'agent' => 'App\\Ai\\SupportAgent'], ['failoverCount' => 1, 'inputTokens' => 120000, 'outputTokens' => 9000, 'cacheReadInputTokens' => 80000, 'cacheWriteInputTokens' => 10000, 'reasoningTokens' => null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null]], ['aiTool', ['agent' => 'App\\Ai\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\OrderTool'], null]] as [$type, $dimensions, $metrics]) {
        RuntimeAggregate::query()->create([
            'project_id' => $project->id,
            'type' => $type,
            'bucket' => $now->copy()->subMinutes(5)->startOfMinute(),
            'sample_rate' => 1,
            'dimension_key' => hash('sha256', $type),
            'dimensions' => $dimensions,
            'count' => 12,
            'error_count' => 1,
            'total_ms' => 9600,
            'max_ms' => 2100,
            'metrics' => $metrics,
            'histogram' => [0, 0, 0, 0, 0, 2, 4, 3, 2, 1, 0, 0],
        ]);
    }

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
