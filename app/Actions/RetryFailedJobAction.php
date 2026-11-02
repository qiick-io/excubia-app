<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;

/**
 * Pushes a failed job back onto its queue through `queue:retry` (which resets attempts and
 * the retry deadline) and removes it from `failed_jobs`.
 */
class RetryFailedJobAction
{
    public function __construct(private readonly FailedJobProviderInterface $failedJobs) {}

    /**
     * @return bool false when no failed job has this UUID
     */
    public function execute(string $uuid): bool
    {
        if ($this->failedJobs->find($uuid) === null) {
            return false;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return true;
    }
}
