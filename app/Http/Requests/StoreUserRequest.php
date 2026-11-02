<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\EffectivePermissionResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Validates a new dashboard user. Only a super-admin may grant the super-admin role.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default()],
            'is_active' => ['boolean'],
            ...$this->roleRules(),
        ];
    }

    /**
     * Role names the actor may assign; the user pages show the same list.
     *
     * @return list<string>
     */
    public static function assignableRolesFor(?User $actor): array
    {
        $roles = Role::query()->orderBy('name')->pluck('name')->all();

        if ($actor !== null && app(EffectivePermissionResolverService::class)->isSuperAdmin($actor)) {
            return array_values($roles);
        }

        return array_values(array_diff($roles, [RoleEnum::SuperAdmin->value]));
    }

    /**
     * The assignable roles with their ids, for the role pickers of the user and group drawers.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function assignableRoleOptionsFor(?User $actor): array
    {
        return array_values(Role::query()
            ->whereIn('name', self::assignableRolesFor($actor))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role): array => ['id' => (int) $role->id, 'name' => $role->name])
            ->all());
    }

    /**
     * The validated role names.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return array_values(array_filter($this->array('roles'), is_string(...)));
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(self::assignableRolesFor($this->user()))],
        ];
    }
}
