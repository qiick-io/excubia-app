<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\StorePushedReportRequest;
use App\Jobs\RecordQueueHeartbeatJob;
use App\Models\AlertLog;
use App\Models\IngestRejection;
use App\Models\Project;
use App\Models\PushedBatch;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Database\Seeders\CreateSuperAdminSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Qiick\Excubia\Laravel\ExcubiaHeartbeatCommand;
use Throwable;

/**
 * Read model of the System page (#117): the health of this Excubia install. Each item has a
 * status (ok, warning, error, unknown) and the `docs/self-hosting.md` anchor of its fix hint.
 * Only names, counts and times: no host, token, path or payload. Per-project rows list only the
 * projects the viewer may see (`Project::visibleTo`), so a non-admin holding `can-show-system`
 * never learns the names of projects restricted to admins (#152).
 *
 * @phpstan-type Status 'ok'|'warning'|'error'|'unknown'
 */
class SystemHealthService
{
    /** The fix hints, `docs/self-hosting.md` "System page". */
    public const DOCS_URL = 'https://github.com/qiick-io/excubia-app/blob/develop/docs/self-hosting.md';

    /** The excubia-core constraint this app accepts (`docs/compatibility.md`), as the first-run wizard shows. */
    public const CORE_CONSTRAINT = '^1.0@beta';

    /** A heartbeat older than this means the scheduler or the queue worker stopped. */
    private const HEARTBEAT_MINUTES = 5;

    /** Runtime is pushed every minute; older than this is stale. */
    private const RUNTIME_STALE_MINUTES = 15;

    private const ALERT_FAILURES_SHOWN = 10;

    /** The login of the development super-admin that `.env.example` used to seed with "password" (#225). */
    private const EXAMPLE_ADMIN_EMAIL = 'superadmin@example.com';

    private const EXAMPLE_ADMIN_PASSWORD = 'password';

    public function __construct(
        private ProjectStalenessService $staleness,
        private SettingsRepositoryService $settings,
    ) {}

    /**
     * @return array<string, mixed> one entry per item: `status`, `hint` and its values
     */
    public function snapshot(User $viewer): array
    {
        $scheduler = $this->scheduler();

        return [
            'docsUrl' => self::DOCS_URL,
            'scheduler' => $scheduler,
            'queue' => $this->queue($scheduler['status'] === 'ok'),
            'pushes' => $this->pushes($viewer),
            'rejections' => $this->rejections(),
            'skippedRuntime' => $this->skippedRuntime($viewer),
            'alertFailures' => $this->alertFailures($viewer),
            'rollups' => $this->rollups(),
            'exampleAdmin' => $this->exampleAdmin(),
            'version' => $this->version(),
        ];
    }

    /**
     * The app version from composer.json (the single version source, as in externa-core), or `dev`.
     */
    public static function appVersion(): string
    {
        return once(function (): string {
            $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

            return is_array($composer) && is_string($composer['version'] ?? null) ? $composer['version'] : 'dev';
        });
    }

    /**
     * The last run of excubia-core's `excubia:heartbeat`, which the core schedules every minute
     * on this app too.
     *
     * ponytail: reads the core's cache key; ask the core for a public accessor if it moves.
     *
     * @return array{status: Status, hint: string, lastRunAt: string|null}
     */
    private function scheduler(): array
    {
        $lastRunAt = $this->cachedTime(ExcubiaHeartbeatCommand::CACHE_KEY);

        return [
            'status' => $this->isFresh($lastRunAt) ? 'ok' : 'error',
            'hint' => 'scheduler-heartbeat',
            'lastRunAt' => $lastRunAt?->toIso8601String(),
        ];
    }

    /**
     * The worker's heartbeat (RecordQueueHeartbeatJob, queued by the scheduler), the backlog of
     * the default queue and the failed jobs. Unknown while the scheduler is down, since nothing
     * queues the heartbeat then.
     *
     * @return array{status: Status, hint: string, connection: string, lastRunAt: string|null, pending: int|null, failed: int}
     */
    private function queue(bool $isSchedulerAlive): array
    {
        $connection = (string) config('queue.default');
        $lastRunAt = $this->cachedTime(RecordQueueHeartbeatJob::CACHE_KEY);
        $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();

        try {
            $pending = Queue::connection()->size();
        } catch (Throwable) {
            $pending = null;
        }

        $status = match (true) {
            $connection === 'sync' || $this->isFresh($lastRunAt) => $failed > 0 ? 'warning' : 'ok',
            ! $isSchedulerAlive => 'unknown',
            default => 'error',
        };

        return [
            'status' => $status,
            'hint' => 'queue-worker',
            'connection' => $connection,
            'lastRunAt' => $lastRunAt?->toIso8601String(),
            'pending' => $pending,
            'failed' => $failed,
        ];
    }

    /**
     * The last report, runtime and exceptions push of each enabled project. Reports are stale
     * after the "Stale after" setting, runtime after RUNTIME_STALE_MINUTES; exceptions are only
     * pushed when there are some, so they are never stale.
     *
     * @return array{status: Status, hint: string, staleAfterHours: int, projects: list<array{name: string, slug: string, reportAt: string|null, isReportStale: bool, runtimeAt: string|null, isRuntimeStale: bool, exceptionsAt: string|null}>}
     */
    private function pushes(User $viewer): array
    {
        $batches = PushedBatch::query()->toBase()
            ->select(['project_id', 'type', DB::raw('max(received_at) as received_at')])
            ->groupBy(['project_id', 'type'])
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->project_id.'|'.$row->type => CarbonImmutable::parse((string) $row->received_at)]);
        $runtimeCutoff = Date::now()->subMinutes(self::RUNTIME_STALE_MINUTES);

        $projects = array_values(Project::query()->visibleTo($viewer)->where('is_enabled', true)->orderBy('name')->get()
            ->map(function (Project $project) use ($batches, $runtimeCutoff): array {
                $runtimeAt = $batches->get($project->id.'|runtime');

                return [
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'reportAt' => $project->last_report_at?->toIso8601String(),
                    'isReportStale' => $this->staleness->isStale($project),
                    'runtimeAt' => $runtimeAt?->toIso8601String(),
                    'isRuntimeStale' => $runtimeAt !== null && $runtimeAt->lt($runtimeCutoff),
                    'exceptionsAt' => $batches->get($project->id.'|exceptions')?->toIso8601String(),
                ];
            })
            ->all());

        $isStale = collect($projects)->contains(fn (array $project): bool => $project['isReportStale'] || $project['isRuntimeStale']);

        return [
            'status' => $isStale ? 'warning' : 'ok',
            'hint' => 'pushes-per-project',
            'staleAfterHours' => $this->settings->staleAfterHours(),
            'projects' => $projects,
        ];
    }

    /**
     * Rejected pushes of the last 24 hours per endpoint, status and reason, most frequent first.
     *
     * @return array{status: Status, hint: string, total: int, rows: list<array{endpoint: string, status: int, reason: string, count: int}>}
     */
    private function rejections(): array
    {
        $rows = array_values(IngestRejection::query()->toBase()
            ->where('bucket', '>=', Date::now()->subDay()->startOfHour())
            ->select(['endpoint', 'status', 'reason', DB::raw('sum(count) as total')])
            ->groupBy(['endpoint', 'status', 'reason'])
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'endpoint' => (string) $row->endpoint,
                'status' => (int) $row->status,
                'reason' => (string) $row->reason,
                'count' => (int) $row->total,
            ])
            ->all());
        $total = array_sum(array_column($rows, 'count'));

        return ['status' => $total > 0 ? 'warning' : 'ok', 'hint' => 'ingest-rejections', 'total' => $total, 'rows' => $rows];
    }

    /**
     * Projects whose pushes carried runtime types this app does not know (#111), skipped on store.
     *
     * @return array{status: Status, hint: string, projects: list<array{name: string, slug: string, count: int}>}
     */
    private function skippedRuntime(User $viewer): array
    {
        $projects = array_values(Project::query()->visibleTo($viewer)->where('skipped_runtime_aggregates', '>', 0)->orderBy('name')->get()
            ->map(fn (Project $project): array => ['name' => $project->name, 'slug' => $project->slug, 'count' => $project->skipped_runtime_aggregates])
            ->all());

        return ['status' => $projects === [] ? 'ok' : 'warning', 'hint' => 'unknown-runtime-types', 'projects' => $projects];
    }

    /**
     * Alerts of the last 24 hours that one channel failed to deliver (#114), the newest first.
     *
     * @return array{status: Status, hint: string, total: int, latest: list<array{project: string, event: string, channels: list<string>, at: string}>}
     */
    private function alertFailures(User $viewer): array
    {
        $query = AlertLog::query()->where('status', AlertLog::FAILED)->where('created_at', '>=', Date::now()->subDay())
            ->whereIn('project_id', Project::query()->visibleTo($viewer)->select('id'));
        $total = (clone $query)->count();
        $latest = array_values($query->with('project:id,name')->latest()->limit(self::ALERT_FAILURES_SHOWN)->get()
            ->map(fn (AlertLog $log): array => [
                'project' => (string) $log->project?->name,
                'event' => $log->event,
                'channels' => array_keys(array_filter($log->deliveries, fn (string $outcome): bool => $outcome === AlertLog::FAILED)),
                'at' => $log->created_at->toIso8601String(),
            ])
            ->all());

        return ['status' => $total > 0 ? 'warning' : 'ok', 'hint' => 'alert-delivery-failures', 'total' => $total, 'latest' => $latest];
    }

    /**
     * Whether `excubia:backfill-runtime-rollups` (#137) still has to run: runtime aggregates exist
     * from before the oldest hourly rollup. Pruning drops rollups by hour and aggregates by
     * minute, so up to an hour of difference is normal.
     *
     * @return array{status: Status, hint: string, isBackfillPending: bool}
     */
    private function rollups(): array
    {
        $oldestAggregate = RuntimeAggregate::query()->min('bucket');
        $oldestRollup = RuntimeHourlyRollup::query()->min('bucket');
        $isPending = $oldestAggregate !== null && ($oldestRollup === null
            || Carbon::parse((string) $oldestAggregate)->lt(Carbon::parse((string) $oldestRollup)->subHour()));

        return ['status' => $isPending ? 'warning' : 'ok', 'hint' => 'runtime-rollups', 'isBackfillPending' => $isPending];
    }

    /**
     * Whether the example super-admin of the development seeder exists on a server (#225): an
     * error while it still has the password "password", a warning otherwise (a guessable login).
     * Local and testing installs are expected to have it.
     *
     * @return array{status: Status, hint: string, exists: bool, hasDefaultPassword: bool}
     */
    private function exampleAdmin(): array
    {
        $user = app()->environment(CreateSuperAdminSeeder::ENVIRONMENTS)
            ? null
            : User::query()->where('email', self::EXAMPLE_ADMIN_EMAIL)->first();
        $hasDefaultPassword = is_string($user?->password) && Hash::check(self::EXAMPLE_ADMIN_PASSWORD, $user->password);

        return [
            'status' => match (true) {
                $hasDefaultPassword => 'error',
                $user !== null => 'warning',
                default => 'ok',
            },
            'hint' => 'example-admin-account',
            'exists' => $user !== null,
            'hasDefaultPassword' => $hasDefaultPassword,
        ];
    }

    /**
     * @return array{status: Status, hint: string, app: string, core: string|null, coreConstraint: string, protocolVersions: list<int>}
     */
    private function version(): array
    {
        return [
            'status' => 'ok',
            'hint' => 'version-and-compatibility',
            'app' => self::appVersion(),
            'core' => InstalledVersions::isInstalled('qiick/excubia-core') ? InstalledVersions::getPrettyVersion('qiick/excubia-core') : null,
            'coreConstraint' => self::CORE_CONSTRAINT,
            'protocolVersions' => StorePushedReportRequest::PROTOCOL_VERSIONS,
        ];
    }

    private function cachedTime(string $key): ?CarbonImmutable
    {
        try {
            $timestamp = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    private function isFresh(?CarbonImmutable $time): bool
    {
        return $time !== null && $time->gte(Date::now()->subMinutes(self::HEARTBEAT_MINUTES));
    }
}
