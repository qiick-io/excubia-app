<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Removes a failed job from `failed_jobs` without retrying it (`queue:forget`).
 */
class DeleteFailedJobAction
{
    public function __construct(private readonly FailedJobProviderInterface $failedJobs) {}

    /**
     * @return bool false when no failed job has this UUID
     */
    public function execute(string $uuid): bool
    {
        return $this->failedJobs->forget($uuid);
    }
}
