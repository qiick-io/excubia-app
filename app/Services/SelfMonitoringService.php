<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuntimeTypeEnum;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Contracts\ServerResourcesContract;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum as CoreRuntimeTypeEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Laravel\ExceptionCollectorService;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Laravel\RuntimeCollectorService;
use Qiick\Excubia\Services\DiskSpaceService;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\HealthSampleService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Services\ServerMetricsService;
use Qiick\Excubia\Services\ServerResourcesService;
use Qiick\Excubia\Services\SymfonyHttpClientService;
use Throwable;

/**
 * Excubia watches its own runtime and exceptions (#84): excubia-core's collectors run on this
 * app with `resources/excubia/self-monitoring.json`, writing to the core's local buffers, which
 * `excubia:system-telemetry` stores on the system project. The project's own `excubia.json` is
 * not used for this, so the core never schedules its HTTP `excubia:push-runtime` here.
 *
 * The runtime types (#219) are not read from that shipped file, which an upgrade overwrites:
 * they come from `EXCUBIA_SELF_MONITORING_TYPES`, else from defaults derived from the installed
 * core, every type the core collects and this app stores, with the chatty `cache` sampled.
 *
 * `server` and `health` are no event types: in a monitored app `excubia:push-runtime` samples
 * them each run. Here `excubia:system-telemetry` does it through the same core services (#220).
 *
 * ponytail: the collectors, the buffer, the payload merge and the samplers are excubia-core
 * classes that `docs/CONTRACTS.md` calls internal; ask the core for a public "collect, sample and
 * drain" API if they move.
 */
class SelfMonitoringService
{
    public const CONFIG_PATH = 'resources/excubia/self-monitoring.json';

    /** `EXCUBIA_SELF_MONITORING_TYPES` value that turns on every available type. */
    public const ALL_TYPES = 'all';

    /**
     * Default sample rates, by core type value: a cache event fires on every read of this app's
     * own heartbeats and settings, so one in ten is kept (counts are scaled back by the rate).
     */
    public const DEFAULT_SAMPLE_RATES = ['cache' => 0.1];

    public function isEnabled(): bool
    {
        return (bool) config('excubia.self_monitoring');
    }

    /**
     * The shipped configuration; its runtime types are replaced by runtimeConfiguration().
     * Null when the shipped file is invalid.
     */
    public function configuration(Application $application): ?ExcubiaConfigurationData
    {
        try {
            return (new ExcubiaConfigurationLoader)->load($application->basePath(self::CONFIG_PATH), $application->basePath());
        } catch (InvalidConfigurationException) {
            return null;
        }
    }

    /**
     * The shipped `runtime` settings with the types of typeSelection() switched on and every
     * other type off, so a type a newer core adds stays off until it is listed or defaulted.
     *
     * @throws InvalidConfigurationException
     */
    public function runtimeConfiguration(ExcubiaConfigurationData $configuration): RuntimeConfigurationData
    {
        $shipped = $configuration->runtime;
        $enabledTypeValues = array_column($this->typeSelection()['types'], 'isEnabled', 'type');

        return new RuntimeConfigurationData(
            isEnabled: $shipped->isEnabled,
            enabledTypes: array_combine(
                array_map(static fn (CoreRuntimeTypeEnum $type): string => $type->value, CoreRuntimeTypeEnum::cases()),
                array_map(static fn (CoreRuntimeTypeEnum $type): bool => $enabledTypeValues[$type->configurationKey()] ?? false, CoreRuntimeTypeEnum::cases()),
            ),
            sampleRates: [...self::DEFAULT_SAMPLE_RATES, ...$shipped->sampleRates],
            slowQueryThresholdMs: $shipped->slowQueryThresholdMs,
            bufferMaxBytes: $shipped->bufferMaxBytes,
            duplicateQueryThreshold: $shipped->duplicateQueryThreshold,
            ignoredCommands: $shipped->ignoredCommands,
            serverName: $shipped->serverName,
            isServerNameVariableInvalid: $shipped->isServerNameVariableInvalid,
            healthCheckIds: $shipped->healthCheckIds,
            healthCheckBudgetMs: $shipped->healthCheckBudgetMs,
            healthTotalBudgetMs: $shipped->healthTotalBudgetMs,
        );
    }

    /**
     * The runtime types the system project can record (the core types this app also stores),
     * which of them are on, and the names of `EXCUBIA_SELF_MONITORING_TYPES` that match none.
     * Names are `excubia.json` keys (`queueWait`, `notifications`); core type values
     * (`notification`) are accepted too.
     *
     * @return array{source: 'default'|'environment', types: list<array{type: string, isEnabled: bool, sampleRate: float}>, unknown: list<string>}
     */
    public function typeSelection(): array
    {
        $configured = trim((string) config('excubia.self_monitoring_types'));
        $names = $configured === '' ? [] : array_values(array_filter(array_map(trim(...), explode(',', $configured)), static fn (string $name): bool => $name !== ''));
        $isDefault = $names === [];
        $isEveryType = in_array(self::ALL_TYPES, $names, true);
        $types = [];
        $matchedNames = [self::ALL_TYPES];

        foreach (self::availableTypes() as $type) {
            $typeNames = [$type->configurationKey(), $type->value];
            $isListed = array_intersect($typeNames, $names) !== [];
            $matchedNames = [...$matchedNames, ...$typeNames];
            $types[] = [
                'type' => $type->configurationKey(),
                'isEnabled' => $isDefault || $isEveryType || $isListed,
                'sampleRate' => self::DEFAULT_SAMPLE_RATES[$type->value] ?? 1.0,
            ];
        }

        return [
            'source' => $isDefault ? 'default' : 'environment',
            'types' => $types,
            'unknown' => array_values(array_unique(array_diff($names, $matchedNames))),
        ];
    }

    /**
     * Registers the core's runtime and exception listeners on this app when self-monitoring is on.
     */
    public function register(Application $application): void
    {
        if (! $this->isEnabled() || ($configuration = $this->configuration($application)) === null) {
            return;
        }

        if ($configuration->runtime->isEnabled) {
            try {
                RuntimeCollectorService::register($application, $this->runtimeConfiguration($configuration));
            } catch (InvalidConfigurationException) {
                // An invalid shipped runtime section must not break the app; nothing is collected.
            }
        }

        if ($configuration->exceptions->isEnabled) {
            ExceptionCollectorService::register($application, $configuration->exceptions);
        }
    }

    /**
     * Appends this machine's `server` sample and, at most once per UTC minute, one `health`
     * aggregate per configured check to the runtime buffer, as `excubia:push-runtime` does in a
     * monitored app, so the system project gets a Servers row and live health (#220).
     *
     * Side effects: reads load, memory and disk of this machine, runs the core's local health
     * probes (database, cache, storage, queue, scheduler heartbeat, Redis; never a data source),
     * and writes `storage/excubia/runtime/health-minute`. A failing sampler is swallowed:
     * monitoring never breaks the telemetry run.
     */
    public function sample(Application $application, ExcubiaConfigurationData $configuration, RuntimeConfigurationData $runtime, RuntimeBufferService $buffer, DateTimeImmutable $now, float $startedAtMs): void
    {
        $server = self::serverIdentity($runtime);

        if ($runtime->isTypeEnabled(CoreRuntimeTypeEnum::Server) && self::isSampled($runtime->sampleRate(CoreRuntimeTypeEnum::Server))) {
            try {
                $sampler = new ServerMetricsService(self::bound($application, ServerResourcesContract::class) ?? new ServerResourcesService, self::diskSpace($application));
                $buffer->append([$sampler->aggregate($server, $application->storagePath(), $runtime->sampleRate(CoreRuntimeTypeEnum::Server), $now->getTimestamp())]);
            } catch (Throwable) {
                // Best effort, as in the core: the buffered data is still stored.
            }
        }

        $minute = gmdate('Y-m-d\TH:i', $now->getTimestamp());
        $minutePath = $application->storagePath('excubia/runtime/health-minute');

        if (! $runtime->isTypeEnabled(CoreRuntimeTypeEnum::Health) || (is_file($minutePath) && file_get_contents($minutePath) === $minute) || ! self::isSampled($runtime->sampleRate(CoreRuntimeTypeEnum::Health))) {
            return;
        }

        try {
            $posture = new LaravelPostureService(self::diskSpace($application));
            $runner = new HealthCheckRunnerService(self::bound($application, HttpClientContract::class) ?? new SymfonyHttpClientService, $now);
            $snapshot = new ProjectSnapshotData(null, null, [], [], null);
            $sampler = new HealthSampleService($runtime->healthCheckBudgetMs, $runtime->healthTotalBudgetMs, self::clockMs(...));
            $buffer->append($sampler->aggregates(
                $runtime->healthCheckIds,
                fn (string $checkId) => $runner->runCheck($snapshot->withLaravelPosture($posture->collect($application, $now, $configuration, [$checkId])), $configuration, $checkId),
                $startedAtMs,
                $server,
                $runtime->sampleRate(CoreRuntimeTypeEnum::Health),
                $now->getTimestamp(),
            ));
            file_put_contents($minutePath, $minute);
        } catch (Throwable) {
            // Best effort, as in the core: the buffered data is still stored.
        }
    }

    /** A monotonic clock in milliseconds, the one HealthSampleService budgets against. */
    public static function clockMs(): float
    {
        return hrtime(true) / 1_000_000;
    }

    /**
     * The core types this app stores, in the app's order (the core's, newer types last). A type of a newer core the app does not
     * know yet would only be skipped on store, so it is never offered.
     *
     * @return list<CoreRuntimeTypeEnum>
     */
    private static function availableTypes(): array
    {
        $types = [];

        foreach (RuntimeTypeEnum::cases() as $storedType) {
            $coreType = CoreRuntimeTypeEnum::tryFrom($storedType->value);

            if ($coreType !== null) {
                $types[] = $coreType;
            }
        }

        return $types;
    }

    /**
     * The server name of `server` and `health` aggregates, as the core names it: the configured
     * name, else a hash of the hostname and the app key, never the hostname.
     */
    private static function serverIdentity(RuntimeConfigurationData $runtime): string
    {
        $applicationKey = config('app.key');
        $hostname = gethostname();

        return ServerMetricsService::serverIdentity($runtime->serverName, is_string($hostname) ? $hostname : '', is_string($applicationKey) ? $applicationKey : '');
    }

    private static function diskSpace(Application $application): DiskSpaceContract
    {
        return self::bound($application, DiskSpaceContract::class) ?? new DiskSpaceService;
    }

    /** True for a run that takes the sample: always at rate 1. */
    private static function isSampled(float $sampleRate): bool
    {
        return $sampleRate >= 1.0 || mt_rand() / mt_getrandmax() < $sampleRate;
    }

    /**
     * The container binding of a core contract when there is one (tests bind fakes), as the core
     * resolves it in `excubia:push-runtime`.
     *
     * @template TContract of object
     *
     * @param  class-string<TContract>  $contract
     * @return TContract|null
     */
    private static function bound(Application $application, string $contract): ?object
    {
        if (! $application->bound($contract)) {
            return null;
        }

        $instance = $application->make($contract);

        return $instance instanceof $contract ? $instance : null;
    }
}
