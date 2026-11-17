<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * Application settings are edited with `can-manage-project-settings`; the alert channels and
 * default severities of Settings → Integrations with `can-manage-alerts` (#22). Settings → AI opens
 * with either: its assistant tools are project settings, its AI price table (#129, #331) feeds the
 * AI budget alerts.
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

    public function viewAi(User $user): bool
    {
        return $this->update($user) || $this->manageAlerts($user);
    }
}
