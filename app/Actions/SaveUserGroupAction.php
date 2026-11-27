<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\SuperAdminGuardService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\ExclusiveSuperAdminException;
use Spatie\Permission\Models\Role;

/**
 * Creates or updates a group and replaces its roles, members and project scope (#253). The group
 * change is logged by the model; roles, members and scope as `roles_synced`, `members_synced`
 * and `project_groups_synced` activities.
 *
 * The save runs through SuperAdminGuardService::guardRoleWrite() over every member before and
 * after the change (read under the group's lock), so no member ends up with super-admin next to another role and only an
 * actor who may manage super-admins makes someone one.
 */
class SaveUserGroupAction
{
    public function __construct(
        private SuperAdminGuardService $superAdminGuard,
    ) {}

    /**
     * @param  ?User  $actor  the acting user; null only for trusted system writes
     * @param  list<string>  $roles  role names
     * @param  list<int>  $userIds
     * @param  list<int>  $projectGroupIds
     *
     * @throws ValidationException when the change would leave no active super-admin
     * @throws ExclusiveSuperAdminException after rolling back a mixed or unauthorised super-admin
     */
    public function execute(?User $actor, UserGroup $group, string $name, ?string $description, array $roles, array $userIds, array $projectGroupIds = []): UserGroup
    {
        return $this->superAdminGuard->guardRoleWrite(
            $actor,
            fn (): array => [...$this->superAdminGuard->lockGroupMemberIds($group), ...$userIds],
            fn (): UserGroup => $this->save($group, $name, $description, $roles, $userIds, $projectGroupIds),
            match (true) {
                $actor === null => null,
                $group->exists => fn () => Gate::forUser($actor)->authorize('update', $group),
                default => fn () => Gate::forUser($actor)->authorize('create', UserGroup::class),
            },
        );
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $userIds
     * @param  list<int>  $projectGroupIds
     */
    private function save(UserGroup $group, string $name, ?string $description, array $roles, array $userIds, array $projectGroupIds): UserGroup
    {
        $group->fill(['name' => $name, 'description' => $description])->save();
        $roleModels = Role::query()->whereIn('name', $roles)->orderBy('name')->get(['id', 'name']);
        $group->roles()->sync($roleModels->pluck('id'));
        $group->users()->sync($userIds);
        // Pivot syncs fire no Spatie event: drop the resolver's per-request cache.
        app(EffectivePermissionResolver::class)->flush();
        $projectGroupChanges = $group->projectGroups()->sync($projectGroupIds);

        // Public ids and names only: the activity log never carries numeric row ids (C74).
        activity()
            ->performedOn($group)
            ->event('members_synced')
            ->withProperties(['user_public_ids' => User::query()->whereKey($userIds)->orderBy('id')->pluck('public_id')->all()])
            ->log('Group members synced');

        activity()
            ->performedOn($group)
            ->event('roles_synced')
            ->withProperties(['role_names' => $roleModels->pluck('name')->values()->all()])
            ->log('Group roles synced');

        if (array_filter($projectGroupChanges) !== []) {
            activity()
                ->performedOn($group)
                ->event('project_groups_synced')
                ->withProperties(['project_group_names' => ProjectGroup::query()->whereKey($projectGroupIds)->orderBy('name')->pluck('name')->all()])
                ->log('Group project scope synced');
        }

        return $group;
    }
}
