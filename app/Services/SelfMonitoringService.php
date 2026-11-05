<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Foundation\Application;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Laravel\ExceptionCollectorService;
use Qiick\Excubia\Laravel\RuntimeCollectorService;

/**
 * Excubia watches its own runtime and exceptions (#84): excubia-core's collectors run on this
 * app with `resources/excubia/self-monitoring.json`, writing to the core's local buffers, which
 * `excubia:system-telemetry` stores on the system project. The project's own `excubia.json` is
 * not used for this, so the core never schedules its HTTP `excubia:push-runtime` here.
 *
 * ponytail: the collectors, the buffer and the payload merge are excubia-core classes that
 * `docs/CONTRACTS.md` calls internal; ask the core for a public "collect and drain" API if they
 * move.
 */
class SelfMonitoringService
{
    public const CONFIG_PATH = 'resources/excubia/self-monitoring.json';

    public function isEnabled(): bool
    {
        return (bool) config('excubia.self_monitoring');
    }

    public function configuration(Application $application): ?ExcubiaConfigurationData
    {
        try {
            return (new ExcubiaConfigurationLoader)->load($application->basePath(self::CONFIG_PATH), $application->basePath());
        } catch (InvalidConfigurationException) {
            return null;
        }
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
            RuntimeCollectorService::register($application, $configuration->runtime);
        }

        if ($configuration->exceptions->isEnabled) {
            ExceptionCollectorService::register($application, $configuration->exceptions);
        }
    }
}
