<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * The activity log is read with `can-show-activity-logs`.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::CanShowActivityLogs->value);
    }
}
