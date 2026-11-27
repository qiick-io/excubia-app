<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RoleEnum;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Qiick\Access\Rules\ExclusiveSuperAdminRoleRule;

/**
 * Validates a group with its roles, members and project scope, for both create and update (route
 * `group` on update). Only admins change the project scope (#253): it grants access to projects,
 * admin-only ones included, as changing a project's visibility does.
 * The roles a user may grant follow StoreUserRequest::assignableRolesFor() (only what they
 * hold, #151). Unless they are a super-admin, nobody may join or leave a group that carries
 * roles or a project scope, nor change the roles of a group they belong to: both would change
 * their own access.
 */
class UserGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        return $group instanceof UserGroup
            ? (bool) $this->user()?->can('update', $group)
            : (bool) $this->user()?->can('create', UserGroup::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $group = $this->route('group');
        $actor = $this->user();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique(UserGroup::class)->ignore($group instanceof UserGroup ? $group->id : null)],
            'description' => ['nullable', 'string', 'max:1000'],
            // Super-admin stands alone here too (#474, decision 2026-11-26).
            'roles' => ['array', new ExclusiveSuperAdminRoleRule],
            'roles.*' => ['string', 'distinct', Rule::in(StoreUserRequest::assignableRolesFor($actor instanceof User ? $actor : null))],
            'users' => ['array'],
            'users.*' => ['integer', 'distinct', Rule::exists(User::class, 'id')],
            'project_groups' => ['array'],
            'project_groups.*' => ['integer', 'distinct', Rule::exists(ProjectGroup::class, 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $actor = $this->user();

            if (! $actor instanceof User) {
                return;
            }

            $permissions = app(EffectivePermissionResolverService::class);
            $group = $this->route('group');
            $currentProjectGroupIds = $group instanceof UserGroup ? self::projectGroupIdsOf($group) : [];
            $requestedProjectGroupIds = collect($this->projectGroupIds())->unique()->sort()->values()->all();

            if ($currentProjectGroupIds !== $requestedProjectGroupIds && ! $permissions->isAdmin($actor)) {
                $validator->errors()->add('project_groups', __('Only admins change which projects a group sees.'));
            }

            // Through any mix of direct and group roles, a member who ends up with super-admin
            // holds nothing else (#474).
            foreach ($this->userIds() as $index => $userId) {
                $member = User::query()->find($userId);

                if ($member instanceof User && self::breaksExclusiveSuperAdmin([...$member->getRoleNames()->all(), ...self::groupRoleNamesOf($member, $group instanceof UserGroup ? $group : null), ...$this->roles()])) {
                    $validator->errors()->add("users.{$index}", __('This member would hold the super-admin role together with other roles.'));
                }
            }

            if ($permissions->isActorSuperAdmin($actor)) {
                return;
            }

            $currentRoles = $group instanceof UserGroup ? $group->roles()->orderBy('name')->pluck('name')->all() : [];
            $requestedRoles = collect($this->roles())->unique()->sort()->values()->all();
            $wasMember = $group instanceof UserGroup && $group->users()->whereKey($actor->getKey())->exists();
            $willBeMember = in_array((int) $actor->getKey(), $this->userIds(), true);

            $grantsAccess = $currentRoles !== [] || $requestedRoles !== [] || $currentProjectGroupIds !== [] || $requestedProjectGroupIds !== [];

            if ($wasMember !== $willBeMember && $grantsAccess) {
                $validator->errors()->add('users', __('You cannot join or leave a group that carries roles or projects.'));
            }

            if (($wasMember || $willBeMember) && $currentRoles !== $requestedRoles) {
                $validator->errors()->add('roles', __('You cannot change the roles of a group you belong to.'));
            }
        }];
    }

    /**
     * The role names a user holds through their live groups, except `$except` (the group being
     * saved, whose requested roles replace its stored ones).
     *
     * @return list<string>
     */
    public static function groupRoleNamesOf(User $user, ?UserGroup $except = null): array
    {
        return array_values(UserGroup::query()
            // Expired memberships grant nothing, as in EffectivePermissionResolverService.
            ->whereHas('users', fn ($users) => $users->whereKey($user->getKey())->where(
                fn ($membership) => $membership->whereNull('user_group_user.expires_at')->orWhere('user_group_user.expires_at', '>', now()),
            ))
            ->when($except !== null, fn ($groups) => $groups->whereKeyNot($except?->getKey()))
            ->with('roles')
            ->get()
            ->flatMap(fn (UserGroup $group) => $group->roles->pluck('name'))
            ->map(strval(...))
            ->all());
    }

    /**
     * Whether a set of role names (with repeats) holds super-admin next to any other role.
     *
     * @param  array<mixed>  $roleNames
     */
    public static function breaksExclusiveSuperAdmin(array $roleNames): bool
    {
        $distinct = array_values(array_unique(array_map(strval(...), $roleNames)));

        return in_array(RoleEnum::SuperAdmin->value, $distinct, true) && count($distinct) > 1;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return array_values(array_filter($this->array('roles'), is_string(...)));
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map(intval(...), array_filter($this->array('users'), is_numeric(...))));
    }

    /**
     * The requested project scope; a request without `project_groups` keeps the group's own.
     *
     * @return list<int>
     */
    public function projectGroupIds(): array
    {
        $group = $this->route('group');

        if (! $this->has('project_groups')) {
            return $group instanceof UserGroup ? self::projectGroupIdsOf($group) : [];
        }

        return array_values(array_map(intval(...), array_filter($this->array('project_groups'), is_numeric(...))));
    }

    /**
     * The group's current project scope, sorted, to compare with the request.
     *
     * @return list<int>
     */
    public static function projectGroupIdsOf(UserGroup $group): array
    {
        return array_values(array_map(intval(...), $group->projectGroups()->orderBy('project_groups.id')->pluck('project_groups.id')->all()));
    }
}
