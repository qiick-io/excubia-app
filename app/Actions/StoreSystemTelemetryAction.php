<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\SelfMonitoringService;
use Qiick\Excubia\Laravel\ExceptionPayloadService;
use Qiick\Excubia\Services\RuntimeBufferService;

/**
 * Moves the runtime and exception batches the core buffered on this app into the system project
 * (#84), through the same actions as `/api/runtime` and `/api/exceptions`. A batch file is
 * deleted only after it is stored, and its id keeps a retry idempotent. With no enabled system
 * project nothing is claimed, so the buffers wait (capped by the core).
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
        $configuration = $this->selfMonitoring->configuration(app());

        if ($project === null || $configuration === null) {
            return $stored;
        }

        $runtime = new RuntimeBufferService(storage_path('excubia/runtime'), $configuration->runtime->bufferMaxBytes);

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
