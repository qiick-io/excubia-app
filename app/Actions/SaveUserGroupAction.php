<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Creates or updates a group and replaces its roles, members and project scope (#253). The group
 * change is logged by the model; roles, members and scope as `roles_synced`, `members_synced`
 * and `project_groups_synced` activities.
 */
class SaveUserGroupAction
{
    public function __construct(private SuperAdminGuardService $superAdminGuard) {}

    /**
     * @param  list<string>  $roles  role names
     * @param  list<int>  $userIds
     * @param  list<int>  $projectGroupIds
     *
     * @throws ValidationException when the change would leave no active super-admin
     */
    public function execute(UserGroup $group, string $name, ?string $description, array $roles, array $userIds, array $projectGroupIds = []): UserGroup
    {
        return $this->superAdminGuard->preventLosingLastSuperAdmin(function () use ($group, $name, $description, $roles, $userIds, $projectGroupIds): UserGroup {
            $group->fill(['name' => $name, 'description' => $description])->save();
            $roleModels = Role::query()->whereIn('name', $roles)->orderBy('name')->get(['id', 'name']);
            $group->roles()->sync($roleModels->pluck('id'));
            $group->users()->sync($userIds);
            $projectGroupChanges = $group->projectGroups()->sync($projectGroupIds);

            activity()
                ->performedOn($group)
                ->event('members_synced')
                ->withProperties(['user_ids' => $userIds])
                ->log('Group members synced');

            activity()
                ->performedOn($group)
                ->event('roles_synced')
                ->withProperties([
                    'role_ids' => $roleModels->pluck('id')->values()->all(),
                    'role_names' => $roleModels->pluck('name')->values()->all(),
                ])
                ->log('Group roles synced');

            if (array_filter($projectGroupChanges) !== []) {
                activity()
                    ->performedOn($group)
                    ->event('project_groups_synced')
                    ->withProperties(['project_group_ids' => $projectGroupIds])
                    ->log('Group project scope synced');
            }

            return $group;
        });
    }
}
