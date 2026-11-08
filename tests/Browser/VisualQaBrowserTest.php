<?php

declare(strict_types=1);

use App\Actions\StorePushedRuntimeAction;
use App\Enums\RoleEnum;
use App\Enums\SeverityEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Str;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * Visual QA screenshots (#194): every page and project tab, full page, at desktop (1440px) and
 * mobile (390px) width, in light and dark, over the axe smoke seed plus one runtime push of
 * every core type, so each runtime card has rows. Nothing is asserted beyond "the page renders
 * without JavaScript errors": the PNGs land in `tests/Browser/Screenshots/visual-*.png` for a human.
 * Excluded from every run by phpunit.xml; run it with
 * `vendor/bin/pest tests/Browser/VisualQaBrowserTest.php --group=visual`.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/** Viewport widths and heights of the two layouts. */
const VISUAL_VIEWPORTS = ['desktop' => [1440, 900], 'mobile' => [390, 844]];

/**
 * Screenshots one page in every viewport and mode, after an optional interaction (a drawer).
 * A page with a drawer keeps the viewport height: the drawer is as tall as the viewport.
 *
 * @param  (callable(mixed): mixed)|null  $interact
 */
function screenshotEverywhere(string $name, string $path, ?callable $interact = null, bool $isOverlay = false): void
{
    foreach (VISUAL_VIEWPORTS as $viewport => [$width, $height]) {
        foreach (['light', 'dark'] as $mode) {
            $page = visitA11y($path, $mode)->resize($width, $height);

            if ($interact !== null) {
                $page = $interact($page);
            }

            // The shell scrolls an inner container, so a full-page shot alone stops at the
            // viewport: grow the viewport by what the page-level scroller hides first. Inner
            // scroll areas (a card's table) are smaller than the viewport and keep their scroll.
            $contentHeight = (int) $page->wait(0.4)->script(<<<'JS'
                () => Math.ceil(Math.max(
                    document.documentElement.scrollHeight,
                    ...[...document.querySelectorAll('*')]
                        .filter((element) => ['auto', 'scroll'].includes(getComputedStyle(element).overflowY) && element.clientHeight >= window.innerHeight * 0.6)
                        .map((element) => window.innerHeight + element.scrollHeight - element.clientHeight),
                ))
                JS);

            $page->resize($width, $isOverlay ? $height : min(max($height, $contentHeight), 12000))
                ->wait(0.3)
                ->assertNoJavaScriptErrors()
                ->screenshot(fullPage: true, filename: "visual-{$name}-{$viewport}-{$mode}");
        }
    }
}

/**
 * Pushes 30 minutes of runtime, one entry of every core type a minute, through the ingest action,
 * so the runtime and AI tabs fill every card and the rollups behind the dashboard exist.
 */
function pushVisualRuntime(Project $project): void
{
    $timed = fn (int $count, float $averageMs): array => [
        'totalMs' => round($count * $averageMs, 2),
        'maxMs' => round($averageMs * 4, 2),
        'histogram' => [0, 1, 2, max(1, intdiv($count, 4)), max(1, intdiv($count, 3)), 3, 2, 1, 1, 0, 0, 0],
    ];

    for ($minutesAgo = 30; $minutesAgo >= 1; $minutesAgo--) {
        $bucket = now()->subMinutes($minutesAgo)->startOfMinute()->utc()->format('Y-m-d\TH:i:00\Z');
        $wave = 1 + ($minutesAgo % 5);
        $base = ['bucket' => $bucket, 'sampleRate' => 1];

        $aggregates = [
            ['type' => 'request', ...$base, 'count' => 120 * $wave, 'errorCount' => $wave - 1, 'route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx', ...$timed(120 * $wave, 180.5)],
            ['type' => 'request', ...$base, 'count' => 400 * $wave, 'errorCount' => 0, 'route' => 'products.index', 'method' => 'GET', 'statusClass' => '2xx', ...$timed(400 * $wave, 42.3)],
            ['type' => 'request', ...$base, 'count' => $wave, 'errorCount' => $wave, 'route' => 'api.webhooks.stripe', 'method' => 'POST', 'statusClass' => '5xx', ...$timed($wave, 950)],
            ['type' => 'slowQuery', ...$base, 'count' => $wave, 'errorCount' => 0, 'sql' => 'select * from orders where customer_id = ? and status in (?, ?) order by created_at desc', 'connection' => 'mysql', ...$timed($wave, 1250)],
            ['type' => 'job', ...$base, 'count' => 30 * $wave, 'errorCount' => $minutesAgo === 12 ? 3 : 0, 'job' => 'App\\Jobs\\SendOrderConfirmation', 'queue' => 'emails', 'connection' => 'redis', 'outcome' => $minutesAgo === 12 ? 'failed' : 'processed', ...$timed(30 * $wave, 320)],
            ['type' => 'scheduledTask', ...$base, 'count' => 1, 'errorCount' => 0, 'task' => 'artisan excubia:push', 'taskId' => str_repeat('ab', 32), 'outcome' => 'finished', ...$timed(1, 2400)],
            ['type' => 'outgoingHttp', ...$base, 'count' => 25 * $wave, 'errorCount' => 1, 'host' => 'api.stripe.com', 'statusClass' => '2xx', ...$timed(25 * $wave, 310)],
            ['type' => 'requestMemory', ...$base, 'count' => 120 * $wave, 'errorCount' => 0, 'route' => 'checkout.store', 'method' => 'POST', 'totalMb' => 120 * $wave * 28.5, 'maxMb' => 64.25],
            ['type' => 'jobMemory', ...$base, 'count' => 30 * $wave, 'errorCount' => 0, 'job' => 'App\\Jobs\\SendOrderConfirmation', 'queue' => 'emails', 'totalMb' => 30 * $wave * 22, 'maxMb' => 41.5],
            ['type' => 'queueWait', ...$base, 'count' => 30 * $wave, 'errorCount' => 0, 'job' => 'App\\Jobs\\SendOrderConfirmation', 'queue' => 'emails', ...$timed(30 * $wave, 1800)],
            ['type' => 'cache', ...$base, 'count' => 900 * $wave, 'errorCount' => 0, 'store' => 'redis', 'keyPrefix' => '[masked]', 'hits' => 800 * $wave, 'misses' => 90 * $wave, 'writes' => 10 * $wave, 'deletes' => $wave],
            ['type' => 'mail', ...$base, 'count' => 6 * $wave, 'errorCount' => $minutesAgo === 7 ? 1 : 0, 'mailable' => 'App\\Mail\\OrderShipped', 'mailer' => 'ses'],
            ['type' => 'notification', ...$base, 'count' => 4 * $wave, 'errorCount' => 0, 'notification' => 'App\\Notifications\\InvoicePaid', 'channel' => 'mail'],
            ['type' => 'logLevel', ...$base, 'count' => 12 * $wave, 'errorCount' => 0, 'level' => 'warning', 'channel' => 'stack'],
            ['type' => 'logLevel', ...$base, 'count' => $wave, 'errorCount' => 0, 'level' => 'error', 'channel' => 'stack'],
            ['type' => 'deprecation', ...$base, 'count' => 3, 'errorCount' => 0, 'source' => 'vendor/laravel/framework', 'message' => 'Implicitly marking parameter $request as nullable is deprecated, the explicit nullable type must be used instead', 'phpVersion' => '8.4'],
            ['type' => 'duplicateQuery', ...$base, 'count' => 2 * $wave, 'errorCount' => 0, 'origin' => 'request', 'name' => 'products.index', 'sql' => 'select * from product_images where product_id = ?', 'connection' => 'mysql', 'totalRepeats' => 48 * $wave, 'maxRepeats' => 24],
            ['type' => 'server', ...$base, 'count' => 1, 'errorCount' => 0, 'server' => 'a1b2c3d4e5f6', 'loadAverage1' => 0.4 * $wave, 'loadAverage5' => 0.9, 'loadAverage15' => 0.7, 'cpuCores' => 8, 'cpuLimit' => 4, 'memoryUsedMb' => 2800 + 100 * $wave, 'memoryTotalMb' => 4096, 'diskUsedGb' => 61.5, 'diskTotalGb' => 80, 'isContainer' => true],
            ['type' => 'server', ...$base, 'count' => 1, 'errorCount' => 0, 'server' => 'f6e5d4c3b2a1', 'loadAverage1' => 0.2, 'loadAverage5' => 0.25, 'loadAverage15' => 0.3, 'cpuCores' => 4, 'memoryUsedMb' => 900, 'memoryTotalMb' => 8192, 'diskUsedGb' => 20, 'diskTotalGb' => 160, 'isContainer' => false],
            ['type' => 'command', ...$base, 'count' => 1, 'errorCount' => 0, 'command' => 'queue:work', 'exitCodeClass' => 'success', ...$timed(1, 60000)],
            ['type' => 'ai', ...$base, 'count' => 3 * $wave, 'errorCount' => $minutesAgo === 3 ? 1 : 0, 'operation' => 'agent', 'agent' => 'App\\Ai\\SupportAgent', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'failoverCount' => 0, ...$timed(3 * $wave, 4200), 'inputTokens' => 3 * $wave * 5200, 'outputTokens' => 3 * $wave * 640, 'cacheReadInputTokens' => 3 * $wave * 3000, 'cacheWriteInputTokens' => 400, 'reasoningTokens' => null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null],
            ['type' => 'ai', ...$base, 'count' => $wave, 'errorCount' => 0, 'operation' => 'embeddings', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'failoverCount' => 0, ...$timed($wave, 180), 'inputTokens' => $wave * 900, 'outputTokens' => 0, 'cacheReadInputTokens' => null, 'cacheWriteInputTokens' => null, 'reasoningTokens' => null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null],
            ['type' => 'aiTool', ...$base, 'count' => 2 * $wave, 'errorCount' => 0, 'agent' => 'App\\Ai\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\LookupOrderTool', ...$timed(2 * $wave, 95)],
            ['type' => 'octane', ...$base, 'count' => 312, 'errorCount' => 0, 'server' => 'web-1', 'octaneServer' => 'swoole', 'workerType' => 'http', 'workerStarts' => 1, 'workerStops' => 1, 'requests' => 305, 'tasks' => 4, 'ticks' => 3, 'totalRequestsAtStop' => 500, 'maxRequestsAtStop' => 500, 'maxMb' => 48.211, 'maxGrowthMb' => $minutesAgo === 9 ? 31.5 : 4.004],
            ['type' => 'reverb', ...$base, 'count' => 120, 'errorCount' => $minutesAgo === 5 ? 1 : 0, 'server' => 'ws-1', 'appId' => 'shop', 'connectionsOpened' => 10 * $wave, 'connectionsRejected' => $minutesAgo === 5 ? 1 : 0, 'connectionsPruned' => 2, 'maxConnections' => 40 + $wave, 'messagesReceived' => 80 * $wave, 'messagesSent' => 120 * $wave, 'publicChannelsCreated' => 3, 'privateChannelsCreated' => 2, 'presenceChannelsCreated' => 1, 'publicChannelsRemoved' => 1, 'privateChannelsRemoved' => 1, 'presenceChannelsRemoved' => 0],
            ['type' => 'billingWebhook', ...$base, 'count' => 2 * $wave, 'errorCount' => $minutesAgo === 4 ? 1 : 0, 'provider' => 'stripe', 'eventType' => 'invoice.payment_failed', ...$timed(2 * $wave, 210), 'handledCount' => 2 * $wave, 'paymentFailedCount' => $wave, 'paymentActionRequiredCount' => 1, 'subscriptionCanceledCount' => 0],
            ['type' => 'auth', ...$base, 'count' => 20 * $wave, 'errorCount' => $wave, 'guard' => 'web', 'loginCount' => 18 * $wave, 'lockoutCount' => $minutesAgo === 6 ? 2 : 0, 'tokenAuthenticationCount' => 4 * $wave],
            ['type' => 'scout', ...$base, 'count' => $wave, 'errorCount' => 0, 'model' => 'App\\Models\\Product', 'engine' => 'meilisearch', 'operation' => 'index', 'modelCount' => 250 * $wave, ...$timed($wave, 640)],
            ['type' => 'scout', ...$base, 'count' => 40 * $wave, 'errorCount' => 0, 'model' => 'App\\Models\\Product', 'engine' => 'meilisearch', 'operation' => 'search'],
            ['type' => 'pennant', ...$base, 'count' => 10 * $wave, 'errorCount' => 0, 'feature' => 'App\\Features\\NewCheckout', 'activeCount' => 6 * $wave, 'inactiveCount' => 3 * $wave, 'resolvedCount' => $wave, 'unknownCount' => 0],
            ['type' => 'pennant', ...$base, 'count' => 2, 'errorCount' => 0, 'feature' => 'App\\Features\\LegacySearch', 'activeCount' => 0, 'inactiveCount' => 2, 'resolvedCount' => 0, 'unknownCount' => 0],
        ];

        app(StorePushedRuntimeAction::class)->execute($project, (string) Str::uuid(), $aggregates, 'v2.4.0', 'production');
    }
}

/**
 * The axe seed, its runtime pushed for every type, more exception groups, an older report for
 * the History tab and two more projects (clean, never reported) for the dashboard and lists.
 */
function seededVisualProject(): Project
{
    $project = seededA11yProject();
    pushVisualRuntime($project);
    $now = now();

    foreach ([['Illuminate\\Database\\QueryException', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 'resolved', 31], ['App\\Exceptions\\StockUnavailableException', 'Not enough stock for the requested quantity', 'ignored', 2]] as $position => [$class, $message, $status, $count]) {
        ExceptionGroup::query()->create([
            'project_id' => $project->id,
            'fingerprint' => str_repeat((string) ($position + 1), 64),
            'environment' => 'production',
            'class' => $class,
            'message' => $message,
            'frames' => [['file' => 'app/Services/OrderService.php', 'line' => 88, 'function' => 'App\\Services\\OrderService::reserve', 'inApp' => true]],
            'previous' => [],
            'context_kind' => 'job',
            'context_name' => 'App\\Jobs\\SendOrderConfirmation',
            'count' => $count,
            'first_seen_at' => $now->copy()->subDays(3),
            'last_seen_at' => $now->copy()->subHours(5),
            'status' => $status,
        ]);
    }

    $latest = $project->healthReports()->latest('id')->firstOrFail();

    foreach ([7, 3] as $daysAgo) {
        $project->healthReports()->create([
            'batch_id' => (string) Str::uuid(),
            'received_at' => $now->copy()->subDays($daysAgo),
            'generated_at' => $now->copy()->subDays($daysAgo),
            'release' => 'v2.'.(4 - $daysAgo % 3).'.0',
            'worst_severity' => SeverityEnum::Critical,
            'critical_count' => 1,
            'high_count' => 3,
            'medium_count' => 5,
            'report' => $latest->report,
        ]);
    }

    $clean = Project::factory()->create(['name' => 'Billing API', 'slug' => 'billing-api']);
    $clean->healthReports()->create([
        'batch_id' => (string) Str::uuid(),
        'received_at' => $now,
        'generated_at' => $now,
        'worst_severity' => SeverityEnum::Info,
        'info_count' => 2,
        'report' => ['checks' => []],
    ]);
    $clean->forceFill(['last_report_at' => $now])->save();
    Project::factory()->create(['name' => 'Marketing site', 'slug' => 'marketing-site']);

    return $project;
}

it('screenshots the guest pages', function () {
    // The first-run wizard needs a fresh install without users (#116).
    screenshotEverywhere('setup-admin', '/setup');

    $admin = User::factory()->create()->assignRole(RoleEnum::SuperAdmin->value);
    screenshotEverywhere('login', '/login');

    $this->actingAs($admin);
    screenshotEverywhere('setup-project', '/setup');
})->group('visual');

it('screenshots every authenticated page and project tab', function () {
    // The assistant page exists only with AI on; the Security page skips its password prompt.
    config(['excubia.ai_enabled' => true]);
    $this->withoutMiddleware(RequirePassword::class);
    $this->actingAs(User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin'])->assignRole(RoleEnum::SuperAdmin->value));
    User::factory()->count(3)->create()->each(fn (User $user) => $user->assignRole(RoleEnum::Reader->value));
    $project = seededVisualProject();

    $openFirstRow = fn (string $text): Closure => fn (mixed $page): mixed => $page->click($text)->wait(0.4);

    $pages = [
        'dashboard' => [route('dashboard', absolute: false)],
        'projects' => [route('projects.index', absolute: false)],
        'project-overview' => [route('projects.show', $project, false)],
        'project-findings' => [route('projects.view', [$project, 'findings'], false)],
        'project-findings-drawer' => [route('projects.view', [$project, 'findings'], false).'?finding='.rawurlencode('composer-audit|guzzlehttp/guzzle|')],
        'project-licenses' => [route('projects.view', [$project, 'licenses'], false)],
        'project-runtime' => [route('projects.view', [$project, 'runtime'], false)],
        'project-ai' => [route('projects.view', [$project, 'ai'], false)],
        'project-exceptions' => [route('projects.view', [$project, 'exceptions'], false)],
        'project-exceptions-drawer' => [route('projects.view', [$project, 'exceptions'], false), $openFirstRow('Payment failed')],
        'project-schedule' => [route('projects.view', [$project, 'schedule'], false)],
        'project-alerts' => [route('projects.view', [$project, 'alerts'], false)],
        'project-plan' => [route('projects.view', [$project, 'plan'], false)],
        'project-history' => [route('projects.view', [$project, 'history'], false)],
        'project-never-reported' => [route('projects.show', 'marketing-site', false)],
        'fleet-readiness' => [route('fleet.show', absolute: false)],
        'fleet-advisories' => [route('fleet.view', 'advisories', false)],
        'fleet-eol' => [route('fleet.view', 'eol', false)],
        'assistant' => [route('ai.index', absolute: false)],
        'settings-profile' => [route('profile.edit', absolute: false)],
        'settings-security' => [route('security.edit', absolute: false)],
        'settings-appearance' => [route('appearance.edit', absolute: false)],
        'settings-appearance-sidebar' => [route('appearance.sidebar.edit', absolute: false)],
        'settings-performance' => [route('performance.edit', absolute: false)],
        'settings-ai' => [route('ai-settings.edit', absolute: false)],
        'settings-ai-prices' => [route('ai-prices.edit', absolute: false)],
        'settings-project' => [route('project.edit', absolute: false)],
        'settings-integrations' => [route('project.integrations.edit', absolute: false)],
        'settings-localization' => [route('project.localization.edit', absolute: false)],
        'settings-project-security' => [route('project.security.edit', absolute: false)],
        'settings-system' => [route('system.index', absolute: false)],
        'settings-users' => [route('users.index', absolute: false)],
        'settings-groups' => [route('groups.index', absolute: false)],
        'settings-roles' => [route('roles.index', absolute: false)],
        'settings-role-create' => [route('roles.create', absolute: false)],
        'settings-permissions' => [route('permissions.index', absolute: false)],
        'settings-jobs' => [route('jobs.index', absolute: false)],
        'settings-activity-logs' => [route('activity-logs.index', absolute: false)],
    ];

    foreach ($pages as $name => $page) {
        screenshotEverywhere($name, $page[0], $page[1] ?? null, str_ends_with($name, '-drawer'));
    }
})->group('visual');
