<?php

declare(strict_types=1);

/*
 * Ingest load test (#195): how many projects one Excubia App instance takes before the pushes
 * slow down.
 *
 * Creates N projects with fresh push tokens in the app's own database, then plays simulated
 * minutes against the running app. Every simulated minute each project pushes, as
 * `excubia:push-runtime` does, one runtime batch (about 60 aggregates of 25 runtime types, `ai`,
 * `octane` and `reverb` included) and, in most minutes, one exceptions batch, plus a full report
 * (`--report-every` minutes, staggered across the projects). Every project's scheduler fires at
 * second 0 of the minute, so the requests of one minute all start at once: curl_multi keeps
 * `--concurrency` of them open, as a reverse proxy in front of the PHP workers would, and the
 * rest wait their turn.
 *
 * Measures the response time (p50, p95, p99) per endpoint, the error rate (any non-2xx, or no
 * answer: status 0), the burst: how long after second 0 each push got its answer, which is what
 * excubia-core waits (it gives up after 5 seconds and retries on its next run, so the share above
 * 5 s is the share of pushes that would time out), the queue backlog (rows of the `jobs` table)
 * after each wave, and the database growth, extrapolated to one simulated day.
 *
 *   php scripts/load/ingest-load.php --url=http://127.0.0.1:8000 --projects=50 --minutes=5
 *
 * Options: --url (required), --projects (10), --minutes (5, simulated minutes), --interval (60:
 * real seconds per simulated minute; 0 plays the minutes back to back to find the ceiling),
 * --concurrency (64), --report-every (60), --report (the report JSON pushed, default the core
 * fixture), --json (print the summary as JSON), --fold (after the run, fold the minutes into
 * hourly rows as excubia:downsample-runtime does and print the size of the runtime and exception
 * occurrence tables per project and day at both resolutions, #212 and #214; use a fresh database,
 * the tables are measured whole and compacted first), --cleanup (delete the load projects and
 * stop).
 *
 * Run it from the repository root with the same environment (`.env` or variables) as the server,
 * so it creates the projects and reads the sizes in the same database. Back-to-back minutes
 * exceed the push rate limit: start the server with a high EXCUBIA_PUSH_REQUESTS_PER_MINUTE.
 * Never point it at a production database: it adds projects and data. Manual only, not in CI by
 * default (docs/self-hosting.md, "Sizing").
 */

use App\Actions\DeleteProjectAction;
use App\Actions\DownsampleExceptionOccurrencesAction;
use App\Actions\DownsampleRuntimeAction;
use App\Actions\RotateProjectTokenAction;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const LOAD_SLUG_PREFIX = 'load-test-';

/** Tables whose growth the summary lists. */
const LOAD_TABLES = [
    'pushed_batches', 'runtime_aggregates', 'runtime_hourly_rollups', 'exception_groups', 'exception_occurrences',
    'health_reports', 'findings', 'finding_events', 'scheduled_task_runs', 'ai_usage_days', 'activity_log', 'cache', 'jobs',
];

$options = getopt('', ['url:', 'projects:', 'minutes:', 'interval:', 'concurrency:', 'report-every:', 'report:', 'json', 'fold', 'cleanup']);
$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (isset($options['cleanup'])) {
    $projects = Project::query()->where('slug', 'like', LOAD_SLUG_PREFIX.'%')->get();
    $projects->each(fn (Project $project) => app(DeleteProjectAction::class)->execute($project));
    fwrite(STDOUT, "Deleted {$projects->count()} load projects.\n");

    exit(0);
}

if (! isset($options['url'])) {
    fwrite(STDERR, "Usage: php scripts/load/ingest-load.php --url=http://127.0.0.1:8000 [--projects=10] [--minutes=5] [--interval=60] [--concurrency=64]\n");

    exit(2);
}

$url = rtrim((string) $options['url'], '/');
$projectCount = max(1, (int) ($options['projects'] ?? 10));
$minutes = max(1, (int) ($options['minutes'] ?? 5));
$interval = max(0.0, (float) ($options['interval'] ?? 60));
$concurrency = max(1, (int) ($options['concurrency'] ?? 64));
$reportEvery = max(1, (int) ($options['report-every'] ?? 60));
$reportFile = (string) ($options['report'] ?? $root.'/tests/Fixtures/core/report-every-check.json');
$report = json_decode((string) file_get_contents($reportFile), true, flags: JSON_THROW_ON_ERROR);

$tokens = loadProjects($projectCount);
$sizeBefore = databaseSize();
$rowsBefore = tableRows();

// Buckets end at the current minute, so the data lands inside the retention and on the dashboards.
$firstMinute = (intdiv(time(), 60) - $minutes) * 60;
$results = [];
$waves = [];
$backlog = [];

for ($minute = 0; $minute < $minutes; $minute++) {
    $startedAt = microtime(true);
    $bucket = $firstMinute + $minute * 60;
    $requests = [];

    foreach ($tokens as $index => $token) {
        $requests[] = ['runtime', $token, runtimeEnvelope($index, $bucket)];

        if (($exceptions = exceptionsEnvelope($index, $bucket)) !== null) {
            $requests[] = ['exceptions', $token, $exceptions];
        }

        if (($minute + $index) % $reportEvery === 0) {
            $requests[] = ['reports', $token, reportEnvelope($report, $bucket)];
        }
    }

    array_push($results, ...pushAll($url, $requests, $concurrency));
    $waves[] = (microtime(true) - $startedAt) * 1000;
    $backlog[] = DB::table('jobs')->count();

    $sleep = $interval - (microtime(true) - $startedAt);

    if ($minute < $minutes - 1 && $sleep > 0) {
        usleep((int) ($sleep * 1_000_000));
    }
}

$summary = summarize($results, $waves, $backlog, $interval, $minutes, $projectCount, $sizeBefore, $rowsBefore);

if (isset($options['fold'])) {
    $summary['fold'] = foldRuntime($firstMinute, $minutes, $projectCount);
}

fwrite(STDOUT, isset($options['json']) ? json_encode($summary, JSON_PRETTY_PRINT)."\n" : formatSummary($summary));

/**
 * Folds every pushed minute into hourly rows (#212, #214) and measures `runtime_aggregates` and
 * `exception_occurrences` (indexes included) before and after, per project and simulated day:
 * the minutes for the per-minute window, the hours for the rest of the retention.
 *
 * @return array{minute_bytes_per_project_day: int, hour_bytes_per_project_day: int, rows_folded: int, occurrence_minute_bytes_per_project_day: int, occurrence_hour_bytes_per_project_day: int, occurrence_rows_folded: int}
 */
function foldRuntime(int $firstMinute, int $minutes, int $projects): array
{
    $minuteBytes = tableSize('runtime_aggregates');
    $occurrenceMinuteBytes = tableSize('exception_occurrences');
    $lastMinute = $firstMinute + ($minutes - 1) * 60;

    // From the oldest minute, through the hour of the last one.
    $before = CarbonImmutable::createFromTimestampUTC($lastMinute)->addHour();
    Cache::forget(DownsampleRuntimeAction::FOLDED_UNTIL_KEY);
    Cache::forget(DownsampleExceptionOccurrencesAction::FOLDED_UNTIL_KEY);
    $folded = app(DownsampleRuntimeAction::class)->execute($before);
    $occurrencesFolded = app(DownsampleExceptionOccurrencesAction::class)->execute($before);
    $hours = intdiv($lastMinute, 3600) - intdiv($firstMinute, 3600) + 1;
    $perProjectDay = fn (int $bytes, int $units, int $unitsPerDay): int => (int) round($bytes / $units * $unitsPerDay / $projects);

    return [
        'minute_bytes_per_project_day' => $perProjectDay($minuteBytes, $minutes, 1440),
        'hour_bytes_per_project_day' => $perProjectDay(tableSize('runtime_aggregates'), $hours, 24),
        'rows_folded' => $folded,
        'occurrence_minute_bytes_per_project_day' => $perProjectDay($occurrenceMinuteBytes, $minutes, 1440),
        'occurrence_hour_bytes_per_project_day' => $perProjectDay(tableSize('exception_occurrences'), $hours, 24),
        'occurrence_rows_folded' => $occurrencesFolded,
    ];
}

/** Bytes of a table and its indexes, compacted first so freed pages do not count. */
function tableSize(string $table): int
{
    $connection = DB::connection();

    return (int) match ($connection->getDriverName()) {
        'sqlite' => (function () use ($connection, $table): int {
            $connection->statement('vacuum');

            return (int) $connection->scalar('select sum(pgsize) from dbstat where name in (select name from sqlite_master where tbl_name = ?)', [$table]);
        })(),
        'pgsql' => (function () use ($connection, $table): int {
            $connection->statement("vacuum full {$table}");

            return (int) $connection->scalar('select pg_total_relation_size(?)', [$table]);
        })(),
        default => (function () use ($connection, $table): int {
            $connection->statement("optimize table {$table}");

            return (int) $connection->scalar("select file_size from information_schema.innodb_tablespaces where name = concat(database(), '/', ?)", [$table]);
        })(),
    };
}

/**
 * Creates the missing load projects and gives each a fresh push token (shown once, so a token
 * from an earlier run cannot be read back). Tokens stay in memory only.
 *
 * @return list<string>
 */
function loadProjects(int $count): array
{
    $tokens = [];

    for ($index = 1; $index <= $count; $index++) {
        $slug = LOAD_SLUG_PREFIX.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $project = Project::query()->firstOrCreate(['slug' => $slug], ['name' => "Load test {$index}", 'is_enabled' => true]);
        $tokens[] = app(RotateProjectTokenAction::class)->execute($project);
    }

    return $tokens;
}

/**
 * Sends every request, `$concurrency` at a time, and returns per request the endpoint, the HTTP
 * status (0 without an answer), its own time and the time since the wave started.
 *
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>}>  $requests
 * @return list<array{endpoint: string, status: int, ms: float, burst_ms: float}>
 */
function pushAll(string $url, array $requests, int $concurrency): array
{
    $multi = curl_multi_init();
    $startedAt = microtime(true);
    $pending = $requests;
    $running = [];
    $results = [];

    $start = function (array $request) use ($multi, $url, &$running): void {
        [$endpoint, $token, $body] = $request;
        $handle = curl_init("{$url}/api/{$endpoint}");
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($multi, $handle);
        $running[spl_object_id($handle)] = $endpoint;
    };

    while ($pending !== [] && count($running) < $concurrency) {
        $start(array_shift($pending));
    }

    do {
        curl_multi_exec($multi, $active);
        curl_multi_select($multi, 0.05);

        while (($done = curl_multi_info_read($multi)) !== false) {
            $handle = $done['handle'];
            $results[] = [
                'endpoint' => $running[spl_object_id($handle)],
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'ms' => curl_getinfo($handle, CURLINFO_TOTAL_TIME_T) / 1000,
                'burst_ms' => (microtime(true) - $startedAt) * 1000,
            ];
            unset($running[spl_object_id($handle)]);
            curl_multi_remove_handle($multi, $handle);

            if ($pending !== []) {
                $start(array_shift($pending));
            }
        }
    } while ($running !== []);

    curl_multi_close($multi);

    return $results;
}

/**
 * One minute of runtime for a project: a stable set of series (so they merge into the same rows
 * as a real app's would) with counts that vary per minute. Deterministic per project and minute.
 *
 * @return array<string, mixed>
 */
function runtimeEnvelope(int $project, int $bucket): array
{
    mt_srand($project * 100_003 + intdiv($bucket, 60));
    $at = gmdate('Y-m-d\TH:i:00\Z', $bucket);
    $base = fn (int $count, int $errors = 0): array => ['bucket' => $at, 'sampleRate' => 1, 'count' => max(1, $count), 'errorCount' => min($errors, max(1, $count))];
    $timed = function (int $count, float $meanMs): array {
        $histogram = array_fill(0, 12, 0);
        $histogram[min(11, (int) log(max(1, $meanMs), 2))] = max(1, $count);

        return ['totalMs' => round($count * $meanMs, 3), 'maxMs' => round($meanMs * mt_rand(15, 40) / 10, 3), 'histogram' => $histogram];
    };
    $aggregates = [];

    foreach (['home', 'dashboard', 'projects.index', 'projects.show', 'orders.store', 'api.orders.index', 'api.orders.show', 'login'] as $route) {
        $method = str_ends_with($route, 'store') || $route === 'login' ? 'POST' : 'GET';
        $count = mt_rand(20, 400);
        $aggregates[] = ['type' => 'request', ...$base($count), 'route' => $route, 'method' => $method, 'statusClass' => '2xx', ...$timed($count, mt_rand(20, 180))];
        $aggregates[] = ['type' => 'requestMemory', ...$base($count), 'route' => $route, 'method' => $method, 'totalMb' => $count * 18.5, 'maxMb' => 42.25];
    }

    $aggregates[] = ['type' => 'request', ...$base(mt_rand(1, 10)), 'route' => 'orders.store', 'method' => 'POST', 'statusClass' => '4xx', ...$timed(3, 40)];
    $aggregates[] = ['type' => 'request', ...$base(2, 2), 'route' => 'api.orders.show', 'method' => 'GET', 'statusClass' => '5xx', ...$timed(2, 900)];

    foreach (['App\\Jobs\\SendInvoice', 'App\\Jobs\\SyncStock', 'App\\Jobs\\ProcessUpload', 'App\\Jobs\\RebuildSearch'] as $job) {
        $count = mt_rand(1, 60);
        $aggregates[] = ['type' => 'job', ...$base($count, mt_rand(0, 1)), 'job' => $job, 'queue' => 'default', 'connection' => 'redis', 'outcome' => 'processed', ...$timed($count, mt_rand(50, 2000))];
        $aggregates[] = ['type' => 'jobMemory', ...$base($count), 'job' => $job, 'queue' => 'default', 'totalMb' => $count * 30.0, 'maxMb' => 64.5];
        $aggregates[] = ['type' => 'queueWait', ...$base($count), 'job' => $job, 'queue' => 'default', ...$timed($count, mt_rand(5, 500))];
    }

    $aggregates[] = ['type' => 'slowQuery', ...$base(mt_rand(1, 5)), 'sql' => 'select * from orders where customer_id = ? order by created_at desc', 'connection' => 'mysql', ...$timed(2, 1200)];
    $aggregates[] = ['type' => 'duplicateQuery', ...$base(3), 'origin' => 'request', 'name' => 'projects.index', 'sql' => 'select * from users where id = ?', 'connection' => 'mysql', 'totalRepeats' => 36, 'maxRepeats' => 12];
    $aggregates[] = ['type' => 'outgoingHttp', ...$base(mt_rand(5, 50)), 'host' => 'api.stripe.com', 'statusClass' => '2xx', ...$timed(20, 240)];
    $aggregates[] = ['type' => 'outgoingHttp', ...$base(mt_rand(1, 20)), 'host' => 'hooks.slack.com', 'statusClass' => '2xx', ...$timed(5, 180)];
    $aggregates[] = ['type' => 'cache', ...$base(mt_rand(100, 900)), 'store' => 'redis', 'keyPrefix' => '[masked]', 'hits' => mt_rand(80, 800), 'misses' => mt_rand(1, 90), 'writes' => mt_rand(1, 90), 'deletes' => mt_rand(0, 10)];
    $aggregates[] = ['type' => 'cache', ...$base(mt_rand(10, 90)), 'store' => 'file', 'keyPrefix' => 'settings', 'hits' => mt_rand(10, 80), 'misses' => 1, 'writes' => 1, 'deletes' => 0];

    foreach (['info', 'warning', 'error'] as $level) {
        $aggregates[] = ['type' => 'logLevel', ...$base(mt_rand(1, 40)), 'level' => $level, 'channel' => 'stack'];
    }

    $aggregates[] = ['type' => 'mail', ...$base(mt_rand(1, 5)), 'mailable' => 'App\\Mail\\InvoiceMail', 'mailer' => 'smtp'];
    $aggregates[] = ['type' => 'notification', ...$base(mt_rand(1, 5)), 'notification' => 'App\\Notifications\\OrderShipped', 'channel' => 'mail'];
    $aggregates[] = ['type' => 'server', ...$base(1), 'server' => 'web-1', 'loadAverage1' => mt_rand(10, 300) / 100, 'loadAverage5' => 0.8, 'loadAverage15' => 0.6, 'cpuCores' => 4, 'cpuLimit' => null, 'memoryUsedMb' => mt_rand(900, 3000), 'memoryTotalMb' => 4096, 'diskUsedGb' => 31.5, 'diskTotalGb' => 80, 'isContainer' => false];

    foreach (['laravel-database', 'laravel-cache', 'laravel-queue'] as $checkId) {
        $aggregates[] = ['type' => 'health', ...$base(1), 'server' => 'web-1', 'checkId' => $checkId, 'status' => 'pass', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => []];
    }

    $tokens = fn (): array => ['inputTokens' => mt_rand(500, 20_000), 'outputTokens' => mt_rand(100, 2000), 'cacheReadInputTokens' => mt_rand(0, 8000), 'cacheWriteInputTokens' => 0, 'reasoningTokens' => null, 'imageInputTokens' => null, 'imageOutputTokens' => null, 'audioSeconds' => null, 'searchUnits' => null];
    $aggregates[] = ['type' => 'ai', ...$base(mt_rand(1, 20)), 'operation' => 'agent', 'agent' => 'App\\Ai\\Agents\\SupportAgent', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'failoverCount' => 0, ...$timed(5, 2400), ...$tokens()];
    $aggregates[] = ['type' => 'ai', ...$base(mt_rand(1, 10)), 'operation' => 'embeddings', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'failoverCount' => 0, ...$timed(5, 120), ...$tokens()];
    $aggregates[] = ['type' => 'aiTool', ...$base(mt_rand(1, 10)), 'agent' => 'App\\Ai\\Agents\\SupportAgent', 'tool' => 'App\\Ai\\Tools\\LookupOrderTool', ...$timed(3, 80)];
    $aggregates[] = ['type' => 'octane', ...$base(mt_rand(100, 900)), 'server' => 'web-1', 'octaneServer' => 'frankenphp', 'workerType' => 'http', 'workerStarts' => 0, 'workerStops' => 0, 'requests' => mt_rand(100, 900), 'tasks' => 0, 'ticks' => 0, 'totalRequestsAtStop' => 0, 'maxRequestsAtStop' => 0, 'maxMb' => 64.5, 'maxGrowthMb' => 1.25];
    $aggregates[] = ['type' => 'reverb', ...$base(mt_rand(10, 200)), 'server' => 'ws-1', 'appId' => 'app', 'connectionsOpened' => mt_rand(0, 20), 'connectionsRejected' => 0, 'connectionsPruned' => 0, 'maxConnections' => mt_rand(10, 300), 'messagesReceived' => mt_rand(10, 200), 'messagesSent' => mt_rand(10, 400), 'publicChannelsCreated' => 1, 'privateChannelsCreated' => 2, 'presenceChannelsCreated' => 0, 'publicChannelsRemoved' => 1, 'privateChannelsRemoved' => 1, 'presenceChannelsRemoved' => 0];
    $aggregates[] = ['type' => 'auth', ...$base(mt_rand(2, 30), 1), 'guard' => 'web', 'loginCount' => mt_rand(1, 20), 'lockoutCount' => 0, 'tokenAuthenticationCount' => mt_rand(0, 50)];
    $aggregates[] = ['type' => 'pennant', ...$base(4), 'feature' => 'App\\Features\\NewCheckout', 'activeCount' => 3, 'inactiveCount' => 1, 'resolvedCount' => 0, 'unknownCount' => 0];
    $aggregates[] = ['type' => 'scout', ...$base(mt_rand(1, 30)), 'model' => 'App\\Models\\Product', 'engine' => 'meilisearch', 'operation' => 'search', 'modelCount' => 0];

    // Every five minutes, like a `*/5` scheduled task and its command.
    if (intdiv($bucket, 60) % 5 === 0) {
        $aggregates[] = ['type' => 'scheduledTask', ...$base(1), 'task' => 'artisan orders:sync', 'taskId' => hash('sha256', 'orders:sync'), 'outcome' => 'finished', ...$timed(1, 3500)];
        $aggregates[] = ['type' => 'command', ...$base(1), 'command' => 'orders:sync', 'exitCodeClass' => 'success', ...$timed(1, 3500)];
    }

    if (mt_rand(1, 10) === 1) {
        $aggregates[] = ['type' => 'billingWebhook', ...$base(1), 'provider' => 'stripe', 'eventType' => 'invoice.paid', ...$timed(1, 150), 'handledCount' => 1, 'paymentFailedCount' => 0, 'paymentActionRequiredCount' => 0, 'subscriptionCanceledCount' => 0];
        $aggregates[] = ['type' => 'deprecation', ...$base(1), 'source' => 'vendor/laravel/framework', 'message' => 'Implicitly marking parameter $x as nullable is deprecated', 'phpVersion' => '8.4'];
    }

    return [
        'protocolVersion' => 1, 'type' => 'runtime', 'batchId' => (string) Str::uuid(), 'sentAt' => gmdate('Y-m-d\TH:i:s\Z', $bucket + 60),
        'runtime' => ['release' => 'v2.4.0', 'environment' => 'production', 'bucketSeconds' => 60, 'aggregates' => $aggregates],
    ];
}

/**
 * Zero to three exception groups out of a pool of 15 per project; null for a quiet minute (core
 * pushes no empty exceptions batch).
 *
 * @return array<string, mixed>|null
 */
function exceptionsEnvelope(int $project, int $bucket): ?array
{
    mt_srand($project * 7_919 + intdiv($bucket, 60));
    $groups = [];

    for ($group = mt_rand(0, 3); $group > 0; $group--) {
        $pick = mt_rand(1, 15);
        $groups[$pick] = [
            'class' => 'Illuminate\\Database\\QueryException',
            'fingerprint' => hash('sha256', "{$project}-{$pick}"),
            'message' => "SQLSTATE[23000]: Integrity constraint violation in step {$pick}",
            'frames' => array_map(fn (int $line): array => ['file' => 'app/Actions/PlaceOrderAction.php', 'line' => $line, 'function' => 'App\\Actions\\PlaceOrderAction::execute', 'inApp' => true], range(10, 50, 4)),
            'previous' => ['PDOException'],
            'context' => ['kind' => 'request', 'name' => 'orders.store'],
            'environment' => 'production',
            'count' => mt_rand(1, 20),
            'firstSeen' => gmdate('Y-m-d\TH:i:s\Z', $bucket + 5),
            'lastSeen' => gmdate('Y-m-d\TH:i:s\Z', $bucket + 55),
        ];
    }

    if ($groups === []) {
        return null;
    }

    return [
        'protocolVersion' => 1, 'type' => 'exceptions', 'batchId' => (string) Str::uuid(), 'sentAt' => gmdate('Y-m-d\TH:i:s\Z', $bucket + 60),
        'exceptions' => ['release' => 'v2.4.0', 'groups' => array_values($groups)],
    ];
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function reportEnvelope(array $report, int $bucket): array
{
    return ['protocolVersion' => 1, 'batchId' => (string) Str::uuid(), 'sentAt' => gmdate('Y-m-d\TH:i:s\Z', $bucket), 'report' => [...$report, 'generatedAt' => gmdate('Y-m-d\TH:i:s\Z', $bucket)]];
}

/** Bytes the database takes on disk: the SQLite file, or the server's own count. */
function databaseSize(): int
{
    $connection = DB::connection();

    return (int) match ($connection->getDriverName()) {
        'sqlite' => (function () use ($connection): int {
            // Fold the WAL into the file first, so its size is the data and not the log.
            $connection->statement('pragma wal_checkpoint(TRUNCATE)');

            clearstatcache();

            return (int) filesize($connection->getDatabaseName());
        })(),
        'pgsql' => $connection->scalar('select pg_database_size(current_database())'),
        // The tablespace files on disk: InnoDB's table statistics lag behind by minutes.
        'mysql' => $connection->scalar("select sum(file_size) from information_schema.innodb_tablespaces where name like concat(database(), '/%')"),
        default => $connection->scalar('select sum(data_length + index_length) from information_schema.tables where table_schema = database()'),
    };
}

/**
 * @return array<string, int>
 */
function tableRows(): array
{
    return array_combine(LOAD_TABLES, array_map(fn (string $table): int => DB::table($table)->count(), LOAD_TABLES));
}

/** The nearest-rank percentile of a list of numbers. */
function percentile(array $values, float $percent): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);

    return (float) $values[max(0, (int) ceil($percent / 100 * count($values)) - 1)];
}

/**
 * @param  list<array{endpoint: string, status: int, ms: float, burst_ms: float}>  $results
 * @param  list<float>  $waves
 * @param  list<int>  $backlog
 * @param  array<string, int>  $rowsBefore
 * @return array<string, mixed>
 */
function summarize(array $results, array $waves, array $backlog, float $interval, int $minutes, int $projects, int $sizeBefore, array $rowsBefore): array
{
    $latency = function (array $results): array {
        $ms = array_column($results, 'ms');
        $errors = count(array_filter($results, fn (array $result): bool => $result['status'] < 200 || $result['status'] >= 300));

        return [
            'requests' => count($results),
            'p50_ms' => round(percentile($ms, 50), 1),
            'p95_ms' => round(percentile($ms, 95), 1),
            'p99_ms' => round(percentile($ms, 99), 1),
            'error_rate' => $results === [] ? 0.0 : round($errors / count($results), 4),
        ];
    };

    $statuses = array_count_values(array_map(fn (array $result): string => (string) $result['status'], $results));
    ksort($statuses);
    $perDay = 1440 / $minutes;
    $rowsAfter = tableRows();

    return [
        'database' => DB::connection()->getDriverName(),
        'projects' => $projects,
        'simulated_minutes' => $minutes,
        'interval_seconds' => $interval,
        'requests_per_second' => round(count($results) / max(0.001, array_sum($waves) / 1000), 1),
        'all' => $latency($results),
        'endpoints' => array_map(fn (string $endpoint): array => $latency(array_values(array_filter($results, fn (array $result): bool => $result['endpoint'] === $endpoint))), array_combine(['runtime', 'exceptions', 'reports'], ['runtime', 'exceptions', 'reports'])),
        'statuses' => $statuses,
        'burst_p50_ms' => round(percentile(array_column($results, 'burst_ms'), 50), 1),
        'burst_p95_ms' => round(percentile(array_column($results, 'burst_ms'), 95), 1),
        'burst_p99_ms' => round(percentile(array_column($results, 'burst_ms'), 99), 1),
        // The share of pushes excubia-core would have given up on (5 s without an answer).
        'burst_over_5s' => $results === [] ? 0.0 : round(count(array_filter($results, fn (array $result): bool => $result['burst_ms'] > 5000)) / count($results), 4),
        'wave_p95_ms' => round(percentile($waves, 95), 1),
        'wave_max_ms' => round(max($waves), 1),
        'queue_backlog_max' => max($backlog),
        'queue_backlog_end' => end($backlog),
        'db_growth_bytes_per_day' => (int) round((databaseSize() - $sizeBefore) * $perDay),
        'rows_per_day' => array_filter(array_map(fn (string $table): int => (int) round(($rowsAfter[$table] - $rowsBefore[$table]) * $perDay), array_combine(LOAD_TABLES, LOAD_TABLES))),
    ];
}

/**
 * @param  array<string, mixed>  $summary
 */
function formatSummary(array $summary): string
{
    $lines = [sprintf(
        '%s, %d projects, %d simulated minutes, %s s per minute: %.1f requests/s',
        $summary['database'], $summary['projects'], $summary['simulated_minutes'], $summary['interval_seconds'], $summary['requests_per_second'],
    )];

    foreach (['all' => $summary['all'], ...$summary['endpoints']] as $name => $stats) {
        $lines[] = sprintf('  %-10s %6d req  p50 %7.1f ms  p95 %7.1f ms  p99 %7.1f ms  errors %5.2f %%', $name, $stats['requests'], $stats['p50_ms'], $stats['p95_ms'], $stats['p99_ms'], $stats['error_rate'] * 100);
    }

    $lines[] = '  statuses   '.json_encode($summary['statuses']);
    $lines[] = sprintf('  burst      answered after second 0: p50 %.0f ms, p95 %.0f ms, p99 %.0f ms; above 5 s %.2f %%', $summary['burst_p50_ms'], $summary['burst_p95_ms'], $summary['burst_p99_ms'], $summary['burst_over_5s'] * 100);
    $lines[] = sprintf('  wave       p95 %.0f ms, max %.0f ms', $summary['wave_p95_ms'], $summary['wave_max_ms']);
    $lines[] = sprintf('  queue      backlog max %d, at the end %d', $summary['queue_backlog_max'], $summary['queue_backlog_end']);
    $lines[] = sprintf('  growth     %.1f MB per simulated day', $summary['db_growth_bytes_per_day'] / 1_048_576);

    foreach ($summary['rows_per_day'] as $table => $rows) {
        $lines[] = sprintf('             %-24s %10d rows per day', $table, $rows);
    }

    if (isset($summary['fold'])) {
        $lines[] = sprintf(
            '  fold       runtime_aggregates per project and day: %.2f MB in minutes, %.3f MB in hours (%d rows folded)',
            $summary['fold']['minute_bytes_per_project_day'] / 1_048_576, $summary['fold']['hour_bytes_per_project_day'] / 1_048_576, $summary['fold']['rows_folded'],
        );
        $lines[] = sprintf(
            '             exception_occurrences per project and day: %.3f MB in minutes, %.3f MB in hours (%d rows folded)',
            $summary['fold']['occurrence_minute_bytes_per_project_day'] / 1_048_576, $summary['fold']['occurrence_hour_bytes_per_project_day'] / 1_048_576, $summary['fold']['occurrence_rows_folded'],
        );
    }

    return implode("\n", $lines)."\n";
}
