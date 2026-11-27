<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a group; members lose its roles, never the last super-admin.
 */
class DeleteUserGroupAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @throws ValidationException when the group holds the last active super-admin
     */
    public function execute(UserGroup $group): void
    {
        $this->superAdminGuard->preventLosingLastSuperAdmin(fn () => $group->delete(), 'group');
    }
}
