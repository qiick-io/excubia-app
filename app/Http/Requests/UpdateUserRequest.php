<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\EffectivePermissionResolverService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates changes to a dashboard user. Users cannot deactivate themselves, and only a
 * super-admin may change their own roles (#151); UpdateUserAction keeps the last active
 * super-admin.
 */
class UpdateUserRequest extends StoreUserRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->target())],
            'password' => ['nullable', 'string', Password::default()],
            'is_active' => ['boolean'],
            ...$this->roleRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $actor = $this->user();
            $target = $this->target();

            if (! $target->is($actor)) {
                return;
            }

            if (! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', __('You cannot deactivate yourself.'));
            }

            if ($this->hasChangedOwnRoles($target) && ! app(EffectivePermissionResolverService::class)->isSuperAdmin($target)) {
                $validator->errors()->add('roles', __('You cannot change your own roles.'));
            }
        }];
    }

    private function hasChangedOwnRoles(User $target): bool
    {
        $current = $target->getRoleNames()->sort()->values()->all();
        $requested = collect($this->roles())->unique()->sort()->values()->all();

        return $current !== $requested;
    }

    private function target(): User
    {
        $target = $this->route('user');

        abort_unless($target instanceof User, 404);

        return $target;
    }
}
