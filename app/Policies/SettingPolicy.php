<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * Application settings are edited with `can-manage-project-settings`; the alert channels and
 * default severities of Settings → Integrations with `can-manage-alerts` (#22).
 */
class SettingPolicy
{
    public function update(User $user): bool
    {
        return $user->can(PermissionEnum::CanManageProjectSettings->value);
    }

    public function manageAlerts(User $user): bool
    {
        return $user->can(PermissionEnum::CanManageAlerts->value);
    }
}
