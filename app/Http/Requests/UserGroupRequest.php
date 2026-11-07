<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserGroup;
use App\Services\EffectivePermissionResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a group with its roles and members, for both create and update (route `group` on update).
 * The roles a user may grant follow StoreUserRequest::assignableRolesFor() (only what they
 * hold, #151). Unless they are a super-admin, nobody may join or leave a group that carries
 * roles, nor change the roles of a group they belong to: both would change their own access.
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
            'roles' => ['array'],
            'roles.*' => ['string', 'distinct', Rule::in(StoreUserRequest::assignableRolesFor($actor instanceof User ? $actor : null))],
            'users' => ['array'],
            'users.*' => ['integer', 'distinct', Rule::exists(User::class, 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $actor = $this->user();

            if (! $actor instanceof User || app(EffectivePermissionResolverService::class)->isSuperAdmin($actor)) {
                return;
            }

            $group = $this->route('group');
            $currentRoles = $group instanceof UserGroup ? $group->roles()->orderBy('name')->pluck('name')->all() : [];
            $requestedRoles = collect($this->roles())->unique()->sort()->values()->all();
            $wasMember = $group instanceof UserGroup && $group->users()->whereKey($actor->getKey())->exists();
            $willBeMember = in_array((int) $actor->getKey(), $this->userIds(), true);

            if ($wasMember !== $willBeMember && ($currentRoles !== [] || $requestedRoles !== [])) {
                $validator->errors()->add('users', __('You cannot join or leave a group that carries roles.'));
            }

            if (($wasMember || $willBeMember) && $currentRoles !== $requestedRoles) {
                $validator->errors()->add('roles', __('You cannot change the roles of a group you belong to.'));
            }
        }];
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
}
