<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Enums\RuntimeTypeEnum;
use App\Models\Project;
use App\Models\RuntimeAggregate;
use App\Models\User;
use App\Services\ProjectRuntimeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * One card per runtime type on the project runtime page (#107), with percentiles from the
 * latency histograms.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Date::setTestNow('2026-11-05 12:30:00');
    $this->project = Project::factory()->create();
    $this->viewer = User::factory()->create()->assignRole(RoleEnum::Reader->value);
});

/**
 * Stores one minute of one series, as StorePushedRuntimeAction would.
 *
 * @param  array<string, string>  $dimensions
 * @param  array<string, mixed>  $values
 */
function cardRow(Project $project, string $type, array $dimensions, array $values = []): RuntimeAggregate
{
    $bucket = $values['bucket'] ?? '2026-11-05 11:58:00';
    $sampleRate = $values['sample_rate'] ?? 1;

    return RuntimeAggregate::query()->create([
        'project_id' => $project->id,
        'type' => $type,
        'bucket' => $bucket,
        'sample_rate' => $sampleRate,
        'dimension_key' => hash('sha256', (string) json_encode($dimensions).($values['release'] ?? '')),
        'dimensions' => $dimensions,
        'release' => $values['release'] ?? null,
        'count' => $values['count'] ?? 10,
        'error_count' => $values['error_count'] ?? 0,
        'total_ms' => $values['total_ms'] ?? null,
        'max_ms' => $values['max_ms'] ?? null,
        'metrics' => $values['metrics'] ?? null,
        'histogram' => $values['histogram'] ?? null,
    ]);
}

function runtimePage(User $viewer, Project $project): TestResponse
{
    return test()->actingAs($viewer)->get(route('projects.view', ['project' => $project->slug, 'view' => 'runtime']))->assertOk();
}

it('sends every card, empty, to a project whose core sends only the default types', function () {
    cardRow($this->project, 'request', ['route' => 'home', 'method' => 'GET', 'statusClass' => '2xx'], ['total_ms' => 100, 'max_ms' => 20]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.skippedAggregates', 0)
        ->where('runtime.requests.count', 10)
        ->where('runtime.requests.p50', null)
        ->where('runtime.queueWait', [])
        ->where('runtime.commands', [])
        ->where('runtime.requestMemory', [])
        ->where('runtime.jobMemory', [])
        ->where('runtime.cache', ['hits' => 0, 'misses' => 0, 'hitRate' => null, 'rows' => []])
        ->where('runtime.mail', [])
        ->where('runtime.notifications', [])
        ->where('runtime.logLevels', ['levels' => [], 'rows' => [], 'timeline' => []])
        ->where('runtime.deprecations', [])
        ->where('runtime.duplicateQueries', [])
        ->where('runtime.servers', [])
        ->where('runtime.octane', ['config' => null, 'leakGrowthMb' => ProjectRuntimeService::OCTANE_LEAK_GROWTH_MB, 'rows' => []])
        ->where('runtime.billing', ['totals' => ['paymentFailed' => 0, 'paymentActionRequired' => 0, 'subscriptionCanceled' => 0], 'rows' => []]));
});

it('shows the skipped aggregates of unknown types, even before any known type arrives', function () {
    $this->project->forceFill(['skipped_runtime_aggregates' => 3])->save();

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.skippedAggregates', 3)
        ->where('runtime.requests.count', 0)
        ->where('runtime.requests.routes', []));
});

it('computes p50, p95 and p99 from histograms across sample rates and releases', function () {
    $dimensions = ['route' => 'checkout.store', 'method' => 'POST', 'statusClass' => '2xx'];
    // 50 in (0, 5], 40 in (50, 100], 10 in (500, 1000]; the slowest took 900 ms.
    cardRow($this->project, 'request', $dimensions, ['count' => 100, 'total_ms' => 10000, 'max_ms' => 900, 'histogram' => [50, 0, 0, 0, 40, 0, 0, 10, 0, 0, 0, 0]]);
    // The same shape sampled at half, in a later release: it doubles every bucket.
    cardRow($this->project, 'request', $dimensions, ['count' => 50, 'total_ms' => 5000, 'max_ms' => 800, 'sample_rate' => 0.5, 'release' => 'v2', 'histogram' => [25, 0, 0, 0, 20, 0, 0, 5, 0, 0, 0, 0]]);
    // Written before histograms: counted, no percentiles of its own.
    cardRow($this->project, 'request', $dimensions, ['count' => 5, 'total_ms' => 50, 'max_ms' => 10, 'bucket' => '2026-11-05 10:00:00', 'release' => 'v1']);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.requests.count', 205)
        ->where('runtime.requests.p50', 5)
        ->where('runtime.requests.p95', 750)
        ->where('runtime.requests.p99', 900)
        ->has('runtime.requests.routes', 1)
        ->where('runtime.requests.routes.0.p95', 750)
        ->where('runtime.requests.timeline.22.ok', 5)
        ->where('runtime.requests.timeline.23.ok', 200));
});

it('leaves skipped scheduled tasks out of their percentiles', function () {
    cardRow($this->project, 'scheduledTask', ['task' => 'reports:send', 'outcome' => 'finished'], ['count' => 4, 'total_ms' => 4000, 'max_ms' => 2000, 'histogram' => [0, 0, 0, 0, 0, 0, 0, 4, 0, 0, 0, 0]]);
    cardRow($this->project, 'scheduledTask', ['task' => 'reports:send', 'outcome' => 'skipped'], ['count' => 96, 'total_ms' => 0, 'max_ms' => 0, 'histogram' => [96, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.scheduledTasks.0.outcomes.finished', 4)
        ->where('runtime.scheduledTasks.0.outcomes.skipped', 96)
        ->where('runtime.scheduledTasks.0.p50', 750));
});

it('shows queue wait and command durations with percentiles', function () {
    cardRow($this->project, 'queueWait', ['job' => 'App\\Jobs\\Ship', 'queue' => 'default'], ['count' => 10, 'total_ms' => 3000, 'max_ms' => 1200, 'histogram' => [0, 0, 0, 0, 0, 10, 0, 0, 0, 0, 0, 0]]);
    cardRow($this->project, 'command', ['command' => 'migrate', 'exitCodeClass' => '0'], ['count' => 3, 'total_ms' => 900, 'max_ms' => 400]);
    cardRow($this->project, 'command', ['command' => 'migrate', 'exitCodeClass' => '1'], ['count' => 1, 'error_count' => 1, 'total_ms' => 100, 'max_ms' => 100]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.queueWait.0.queue', 'default')
        ->where('runtime.queueWait.0.averageMs', 300)
        ->where('runtime.queueWait.0.p50', 175)
        ->has('runtime.commands', 1)
        ->where('runtime.commands.0.command', 'migrate')
        ->where('runtime.commands.0.count', 4)
        ->where('runtime.commands.0.errors', 1)
        ->where('runtime.commands.0.averageMs', 250)
        ->where('runtime.commands.0.maxMs', 400));
});

it('shows peak memory per route and per job', function () {
    cardRow($this->project, 'requestMemory', ['route' => 'export', 'method' => 'GET'], ['count' => 4, 'metrics' => ['totalMb' => 200, 'maxMb' => 80]]);
    cardRow($this->project, 'requestMemory', ['route' => 'export', 'method' => 'GET'], ['count' => 2, 'sample_rate' => 0.5, 'release' => 'v2', 'metrics' => ['totalMb' => 100, 'maxMb' => 120]]);
    cardRow($this->project, 'jobMemory', ['job' => 'App\\Jobs\\Import', 'queue' => 'imports'], ['count' => 1, 'metrics' => ['totalMb' => 512, 'maxMb' => 512]]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.requestMemory.0.route', 'export')
        ->where('runtime.requestMemory.0.count', 8)
        ->where('runtime.requestMemory.0.averageMb', 50)
        ->where('runtime.requestMemory.0.maxMb', 120)
        ->where('runtime.jobMemory.0.queue', 'imports')
        ->where('runtime.jobMemory.0.maxMb', 512));
});

it('shows the cache hit rate per store and key prefix', function () {
    cardRow($this->project, 'cache', ['store' => 'redis', 'keyPrefix' => 'users'], ['count' => 100, 'metrics' => ['hits' => 75, 'misses' => 15, 'writes' => 8, 'deletes' => 2]]);
    cardRow($this->project, 'cache', ['store' => 'redis', 'keyPrefix' => '[masked]'], ['count' => 10, 'metrics' => ['hits' => 0, 'misses' => 10, 'writes' => 0, 'deletes' => 0]]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.cache.hits', 75)
        ->where('runtime.cache.misses', 25)
        ->where('runtime.cache.hitRate', 75)
        ->where('runtime.cache.rows.0.keyPrefix', 'users')
        ->where('runtime.cache.rows.0.hitRate', 83.3)
        ->where('runtime.cache.rows.0.writes', 8)
        ->where('runtime.cache.rows.1.keyPrefix', '[masked]')
        ->where('runtime.cache.rows.1.hitRate', 0));
});

it('shows sent and failed mail and notifications', function () {
    cardRow($this->project, 'mail', ['mailable' => 'App\\Mail\\Invoice', 'mailer' => 'smtp'], ['count' => 20, 'error_count' => 2]);
    cardRow($this->project, 'notification', ['notification' => 'App\\Notifications\\Shipped', 'channel' => 'mail'], ['count' => 5, 'error_count' => 1, 'sample_rate' => 0.5]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.mail.0.mailable', 'App\\Mail\\Invoice')
        ->where('runtime.mail.0.count', 20)
        ->where('runtime.mail.0.errors', 2)
        ->where('runtime.notifications.0.channel', 'mail')
        ->where('runtime.notifications.0.count', 10)
        ->where('runtime.notifications.0.errors', 2));
});

it('shows log entries per level and channel and error-level entries per hour', function () {
    cardRow($this->project, 'logLevel', ['level' => 'error', 'channel' => 'stack'], ['count' => 6]);
    cardRow($this->project, 'logLevel', ['level' => 'error'], ['count' => 2, 'bucket' => '2026-11-05 10:15:00']);
    cardRow($this->project, 'logLevel', ['level' => 'info', 'channel' => 'daily'], ['count' => 50]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.logLevels.levels', [['level' => 'error', 'count' => 8], ['level' => 'info', 'count' => 50]])
        ->has('runtime.logLevels.rows', 3)
        ->where('runtime.logLevels.rows.0.channel', 'daily')
        ->where('runtime.logLevels.rows.2.level', 'error')
        ->where('runtime.logLevels.rows.2.channel', null)
        ->has('runtime.logLevels.timeline', 25)
        ->where('runtime.logLevels.timeline.22.count', 2)
        ->where('runtime.logLevels.timeline.23.count', 6));
});

it('shows deprecations and N+1 query shapes', function () {
    cardRow($this->project, 'deprecation', ['source' => 'vendor/acme/pdf', 'message' => 'Creation of dynamic property is deprecated', 'phpVersion' => '8.4'], ['count' => 7]);
    cardRow($this->project, 'duplicateQuery', ['origin' => 'request', 'name' => 'orders.index', 'sql' => 'select * from users where id = ?', 'connection' => 'mysql'], ['count' => 4, 'metrics' => ['totalRepeats' => 60, 'maxRepeats' => 25]]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.deprecations.0.source', 'vendor/acme/pdf')
        ->where('runtime.deprecations.0.phpVersion', '8.4')
        ->where('runtime.deprecations.0.count', 7)
        ->where('runtime.duplicateQueries.0.name', 'orders.index')
        ->where('runtime.duplicateQueries.0.count', 4)
        ->where('runtime.duplicateQueries.0.averageRepeats', 15)
        ->where('runtime.duplicateQueries.0.maxRepeats', 25));
});

it('shows the latest sample of each server, gauges merged by max', function () {
    $gauges = ['loadAverage1' => 0.5, 'loadAverage5' => 0.4, 'loadAverage15' => 0.3, 'cpuCores' => 8, 'cpuLimit' => null, 'memoryUsedMb' => 1000, 'memoryTotalMb' => 4000, 'diskUsedGb' => 20, 'diskTotalGb' => 100, 'isContainer' => false];
    cardRow($this->project, 'server', ['server' => 'web-1'], ['count' => 1, 'bucket' => '2026-11-05 11:00:00', 'metrics' => [...$gauges, 'loadAverage1' => 7.5]]);
    cardRow($this->project, 'server', ['server' => 'web-1'], ['count' => 1, 'metrics' => $gauges]);
    cardRow($this->project, 'server', ['server' => 'web-1'], ['count' => 1, 'release' => 'v2', 'metrics' => [...$gauges, 'loadAverage1' => 1.25, 'cpuLimit' => 2, 'isContainer' => true]]);
    cardRow($this->project, 'server', ['server' => '0a1b2c3d4e5f'], ['count' => 1, 'metrics' => [...$gauges, 'cpuCores' => null, 'memoryUsedMb' => null, 'memoryTotalMb' => null]]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->has('runtime.servers', 2)
        ->where('runtime.servers.0.server', '0a1b2c3d4e5f')
        ->where('runtime.servers.0.memoryTotalMb', null)
        ->where('runtime.servers.0.isContainer', false)
        ->where('runtime.servers.1.server', 'web-1')
        ->where('runtime.servers.1.loadAverage1', 1.25)
        ->where('runtime.servers.1.cpuLimit', 2)
        ->where('runtime.servers.1.isContainer', true)
        ->where('runtime.servers.1.lastAt', '2026-11-05T11:58:00Z'));
});

it('reads every summed and maxed metric but the server gauges and health readings in SQL', function () {
    $metrics = [];

    foreach (RuntimeTypeEnum::cases() as $type) {
        // Server gauges and health readings (#115) have their own reading paths.
        if (! in_array($type, [RuntimeTypeEnum::Server, RuntimeTypeEnum::Health], true)) {
            $metrics = [...$metrics, ...$type->metrics()];
        }
    }

    $sqlMetrics = ProjectRuntimeService::SQL_METRICS;
    ksort($metrics);
    ksort($sqlMetrics);

    expect($sqlMetrics)->toBe($metrics);
});

it('shows Octane workers per server and worker type with the leak hint and max_requests (#168)', function () {
    $octane = fn (array $metrics): array => ['metrics' => [
        'workerStarts' => 0, 'workerStops' => 0, 'requests' => 0, 'tasks' => 0, 'ticks' => 0,
        'totalRequestsAtStop' => 0, 'maxRequestsAtStop' => 0, 'maxMb' => 0, 'maxGrowthMb' => 0, ...$metrics,
    ]];
    $http = ['server' => 'web-1', 'octaneServer' => 'swoole', 'workerType' => 'http'];

    cardRow($this->project, 'octane', $http, ['count' => 300, 'error_count' => 1, ...$octane(['workerStarts' => 2, 'workerStops' => 2, 'requests' => 290, 'totalRequestsAtStop' => 900, 'maxRequestsAtStop' => 500, 'maxMb' => 48.211, 'maxGrowthMb' => 12.004])]);
    cardRow($this->project, 'octane', $http, ['bucket' => '2026-11-05 11:59:00', 'count' => 100, ...$octane(['requests' => 100, 'maxMb' => 90.5, 'maxGrowthMb' => 40.25])]);
    // Sampled at 50 %: the counts are estimated, the requests at stop are each worker's own.
    cardRow($this->project, 'octane', [...$http, 'workerType' => 'task'], ['sample_rate' => 0.5, 'count' => 20, ...$octane(['workerStarts' => 1, 'workerStops' => 1, 'tasks' => 5, 'ticks' => 14, 'totalRequestsAtStop' => 0])]);
    // A server this app does not know yet (#169) is shown as sent.
    cardRow($this->project, 'octane', [...$http, 'octaneServer' => 'futureServer'], ['count' => 5, ...$octane(['requests' => 5, 'maxMb' => 20])]);
    $this->project->healthReports()->create([
        'batch_id' => (string) Str::uuid(), 'received_at' => now(), 'generated_at' => now(),
        'report' => ['checks' => [['checkId' => 'laravel-octane-config', 'status' => 'pass', 'findings' => [
            ['checkId' => 'laravel-octane-config', 'subject' => 'octane.server', 'status' => 'pass', 'severity' => 'info', 'details' => ['server' => 'swoole', 'maxRequests' => 500]],
        ]]]],
    ]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.octane.config', ['server' => 'swoole', 'maxRequests' => 500])
        ->has('runtime.octane.rows', 3)
        ->where('runtime.octane.rows.0', [
            'server' => 'web-1', 'octaneServer' => 'swoole', 'workerType' => 'http',
            'requests' => 390, 'tasks' => 0, 'ticks' => 0, 'workerStarts' => 2, 'workerStops' => 2, 'errors' => 1,
            'maxMb' => 90.5, 'maxGrowthMb' => 40.25, 'isPossibleLeak' => true,
            'averageRequestsAtStop' => 450, 'maxRequestsAtStop' => 500, 'lastAt' => '2026-11-05T11:59:00Z',
        ])
        ->where('runtime.octane.rows.1.octaneServer', 'futureServer')
        ->where('runtime.octane.rows.1.isPossibleLeak', false)
        ->where('runtime.octane.rows.1.averageRequestsAtStop', null)
        ->where('runtime.octane.rows.2.workerType', 'task')
        ->where('runtime.octane.rows.2.tasks', 10)
        ->where('runtime.octane.rows.2.ticks', 28)
        ->where('runtime.octane.rows.2.workerStops', 2)
        ->where('runtime.octane.rows.2.averageRequestsAtStop', 0)
        ->where('runtime.octane.rows.2.maxRequestsAtStop', 0));
});

it('leaves max_requests out when the latest report has no laravel-octane-config details', function () {
    cardRow($this->project, 'octane', ['server' => 'web-1', 'octaneServer' => 'frankenphp', 'workerType' => 'http'], ['count' => 3, 'metrics' => [
        'workerStarts' => 1, 'workerStops' => 0, 'requests' => 2, 'tasks' => 0, 'ticks' => 0, 'totalRequestsAtStop' => 0, 'maxRequestsAtStop' => 0, 'maxMb' => 30, 'maxGrowthMb' => 0,
    ]]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.octane.config', null)
        ->where('runtime.octane.rows.0.requests', 2)
        ->where('runtime.octane.rows.0.maxRequestsAtStop', null));
});

it('shows billing webhooks per provider and event type with the payment signal totals (#178)', function () {
    $billing = fn (array $metrics): array => ['handledCount' => 0, 'paymentFailedCount' => 0, 'paymentActionRequiredCount' => 0, 'subscriptionCanceledCount' => 0, ...$metrics];

    cardRow($this->project, 'billingWebhook', ['provider' => 'stripe', 'eventType' => 'invoice.payment_failed'], [
        'count' => 10, 'error_count' => 2, 'total_ms' => 500, 'max_ms' => 120, 'histogram' => [0, 0, 5, 3, 2, 0, 0, 0, 0, 0, 0, 0],
        'metrics' => $billing(['handledCount' => 8, 'paymentFailedCount' => 10]),
    ]);
    // Sampled at 50 %: the counts and the signals are estimated.
    cardRow($this->project, 'billingWebhook', ['provider' => 'stripe', 'eventType' => 'customer.subscription.deleted'], [
        'sample_rate' => 0.5, 'count' => 3, 'total_ms' => 30, 'max_ms' => 15, 'metrics' => $billing(['handledCount' => 3, 'subscriptionCanceledCount' => 3]),
    ]);
    // A provider this app does not know yet (#169) is shown as sent; an event type without a Cashier handler is neither handled nor failed.
    cardRow($this->project, 'billingWebhook', ['provider' => 'futureProvider', 'eventType' => 'unknown'], [
        'count' => 1, 'total_ms' => 2, 'max_ms' => 2, 'metrics' => $billing([]),
    ]);

    runtimePage($this->viewer, $this->project)->assertInertia(fn (Assert $page) => $page
        ->where('runtime.billing.totals', ['paymentFailed' => 10, 'paymentActionRequired' => 0, 'subscriptionCanceled' => 6])
        ->has('runtime.billing.rows', 3)
        ->where('runtime.billing.rows.0', [
            'provider' => 'stripe', 'eventType' => 'invoice.payment_failed',
            'count' => 10, 'errors' => 2, 'averageMs' => 50, 'maxMs' => 120, 'p50' => 25, 'p95' => 87.5, 'p99' => 97.5,
            'handled' => 8, 'errorRate' => 20, 'paymentFailed' => 10, 'paymentActionRequired' => 0, 'subscriptionCanceled' => 0,
            'lastAt' => '2026-11-05T11:58:00Z',
        ])
        ->where('runtime.billing.rows.1.eventType', 'customer.subscription.deleted')
        ->where('runtime.billing.rows.1.count', 6)
        ->where('runtime.billing.rows.1.handled', 6)
        ->where('runtime.billing.rows.1.subscriptionCanceled', 6)
        ->where('runtime.billing.rows.1.p95', null)
        ->where('runtime.billing.rows.2.provider', 'futureProvider')
        ->where('runtime.billing.rows.2.handled', 0)
        ->where('runtime.billing.rows.2.errorRate', 0));
});
