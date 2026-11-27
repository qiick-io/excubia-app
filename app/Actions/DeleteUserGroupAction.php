<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Qiick\Access\ExclusiveSuperAdminException;

/**
 * Deletes a group; members lose its roles, never the last super-admin. The delete runs through
 * SuperAdminGuardService::guardRoleWrite() over the members, because dropping a group can also
 * turn a mixed holder into an effective super-admin.
 */
class DeleteUserGroupAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @param  ?User  $actor  the acting user; null only for trusted system writes
     *
     * @throws ValidationException when the group holds the last active super-admin
     * @throws ExclusiveSuperAdminException when an actor who may not manage super-admins would make one
     */
    public function execute(?User $actor, UserGroup $group): void
    {
        $this->superAdminGuard->guardRoleWrite(
            $actor,
            fn (): array => $this->superAdminGuard->lockGroupMemberIds($group),
            fn () => $group->delete(),
            'group',
        );
    }
}
