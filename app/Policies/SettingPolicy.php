<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * Application settings are edited with `can-manage-project-settings`.
 */
class SettingPolicy
{
    public function update(User $user): bool
    {
        return $user->can(PermissionEnum::CanManageProjectSettings->value);
    }
}
