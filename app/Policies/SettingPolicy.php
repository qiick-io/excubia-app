<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * Application settings are edited with `settings.manage`.
 */
class SettingPolicy
{
    public function update(User $user): bool
    {
        return $user->can(PermissionEnum::SettingsManage->value);
    }
}
