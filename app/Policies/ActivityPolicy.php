<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;

/**
 * The activity log is read with `can-show-activity-logs`.
 */
class ActivityPolicy
{
    public function __construct(private EffectivePermissionResolverService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->hasPermission($user, PermissionEnum::CanShowActivityLogs->value);
    }
}
