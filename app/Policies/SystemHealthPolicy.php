<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;

/**
 * The System page (#117) is viewed with `can-show-system` (super-admin by default). It has no
 * model, so the policy is registered for SystemHealthService.
 */
class SystemHealthPolicy
{
    public function __construct(private EffectivePermissionResolverService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->hasPermission($user, PermissionEnum::CanShowSystem->value);
    }
}
