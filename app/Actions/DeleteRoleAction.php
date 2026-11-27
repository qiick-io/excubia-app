<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Qiick\Access\ExclusiveSuperAdminException;
use Spatie\Permission\Models\Role;

/**
 * Deletes a custom role. Its assignments go with it (foreign-key cascade, directly and in groups),
 * so the delete runs through SuperAdminGuardService::guardRoleWrite() over every holder: when the
 * role was a mixed holder's only other role, dropping it would hand them the super-admin bypass.
 */
class DeleteRoleAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @param  ?User  $actor  the acting user; null only for trusted system writes
     *
     * @throws ValidationException when the change would leave no active super-admin
     * @throws ExclusiveSuperAdminException when an actor who may not manage super-admins would make one
     */
    public function execute(?User $actor, Role $role): void
    {
        $this->superAdminGuard->guardRoleWrite(
            $actor,
            fn (): array => $this->superAdminGuard->lockRoleHolderIds($role),
            fn () => $role->delete(),
        );
    }
}
