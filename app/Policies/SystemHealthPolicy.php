<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * The System page (#117) is viewed with `can-show-system` (super-admin by default). It has no
 * model, so the policy is registered for SystemHealthService.
 */
class SystemHealthPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionEnum::CanShowSystem->value);
    }
}
