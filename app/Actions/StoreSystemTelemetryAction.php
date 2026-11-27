<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\SelfMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Laravel\ExceptionPayloadService;
use Qiick\Excubia\Services\RuntimeBufferService;

/**
 * Moves the runtime and exception batches the core buffered on this app into the system project
 * (#84), through the same actions as `/api/runtime` and `/api/exceptions`. A batch file is
 * deleted only after it is stored, and its id keeps a retry idempotent. With no enabled system
 * project nothing is claimed, so the buffers wait (capped by the core).
 *
 * Before the drain, the `server` and `health` samples of this machine are appended to the
 * runtime buffer when those types are on (#220), so they are stored in the same run, as
 * `excubia:push-runtime` sends them in a monitored app.
 */
class StoreSystemTelemetryAction
{
    public function __construct(
        private SelfMonitoringService $selfMonitoring,
        private StorePushedRuntimeAction $storeRuntime,
        private StorePushedExceptionsAction $storeExceptions,
    ) {}

    /**
     * @return array{runtime: int, exceptions: int} batches stored
     */
    public function execute(): array
    {
        $stored = ['runtime' => 0, 'exceptions' => 0];
        $project = Project::query()->where('is_system', true)->where('is_enabled', true)->first();
        $startedAtMs = SelfMonitoringService::clockMs();
        $configuration = $this->selfMonitoring->configuration(app());

        if ($project === null || $configuration === null) {
            return $stored;
        }

        $runtime = new RuntimeBufferService(storage_path('excubia/runtime'), $configuration->runtime->bufferMaxBytes);

        if ($this->selfMonitoring->isEnabled()) {
            try {
                $runtimeConfiguration = $this->selfMonitoring->runtimeConfiguration($configuration);
                $this->selfMonitoring->sample(app(), $configuration, $runtimeConfiguration, $runtime, CarbonImmutable::instance(Date::now())->utc(), $startedAtMs);
            } catch (InvalidConfigurationException) {
                // An invalid shipped runtime section takes no samples; the buffers are still stored.
            }
        }

        foreach ($runtime->claimBatches() as $batch) {
            $aggregates = $runtime->read($batch['path']);

            if ($aggregates !== [] && $this->storeRuntime->execute($project, $batch['batchId'], $aggregates)) {
                $stored['runtime']++;
            }

            $runtime->delete($batch['path']);
        }

        $exceptions = new RuntimeBufferService(storage_path('excubia/exceptions'), $configuration->exceptions->bufferMaxBytes);

        foreach ($exceptions->claimBatches() as $batch) {
            $groups = ExceptionPayloadService::merge($exceptions->readEntries($batch['path']));

            if ($groups !== [] && $this->storeExceptions->execute($project, $batch['batchId'], $groups)) {
                $stored['exceptions']++;
            }

            $exceptions->delete($batch['path']);
        }

        return $stored;
    }
}
