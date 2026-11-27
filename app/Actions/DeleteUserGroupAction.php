<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Qiick\Access\ExclusiveSuperAdmin;
use Qiick\Access\ExclusiveSuperAdminException;

/**
 * Deletes a group; members lose its roles, never the last super-admin. The delete runs inside
 * qiick/laravel-access ExclusiveSuperAdmin::write() over the members, because dropping a group
 * can also turn a mixed holder into an effective super-admin.
 */
class DeleteUserGroupAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
        private ExclusiveSuperAdmin $exclusiveSuperAdmin,
    ) {}

    /**
     * @param  ?User  $actor  the acting user; null only for trusted system writes
     *
     * @throws ValidationException when the group holds the last active super-admin
     * @throws ExclusiveSuperAdminException when an actor who may not manage super-admins would make one
     */
    public function execute(?User $actor, UserGroup $group): void
    {
        $delete = fn () => $this->superAdminGuard->preventLosingLastSuperAdmin(fn () => $group->delete(), 'group');
        $members = array_values($group->users()->get()->all());

        if ($members === []) {
            $delete();

            return;
        }

        $this->exclusiveSuperAdmin->write($members, $delete, $actor);
    }
}
