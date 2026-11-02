<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a group with its roles and members, for both create and update (route `group` on update).
 * The roles a user may grant follow StoreUserRequest::assignableRolesFor().
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
