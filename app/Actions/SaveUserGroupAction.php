<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Creates or updates a group and replaces its roles and members.
 */
class SaveUserGroupAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @param  list<string>  $roles  role names
     * @param  list<int>  $userIds
     *
     * @throws ValidationException when the change would leave no active super-admin
     */
    public function execute(UserGroup $group, string $name, ?string $description, array $roles, array $userIds): UserGroup
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($group, $name, $description, $roles, $userIds): UserGroup {
            $group->fill(['name' => $name, 'description' => $description])->save();
            $group->roles()->sync(Role::query()->whereIn('name', $roles)->pluck('id'));
            $group->users()->sync($userIds);

            return $group;
        });
    }
}
