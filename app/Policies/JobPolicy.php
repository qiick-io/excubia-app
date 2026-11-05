<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * The queue monitor (`jobs` and `failed_jobs` tables) is viewed with `can-show-jobs` and retried or deleted with `can-manage-jobs`.
 * Queue rows have no Eloquent model, so the policy is registered for JobMonitorQueryService.
 */
class JobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::CanShowJobs->value);
    }

    public function retry(User $user): bool
    {
        return $user->can(PermissionEnum::CanManageJobs->value);
    }

    public function delete(User $user): bool
    {
        return $this->retry($user);
    }
}
