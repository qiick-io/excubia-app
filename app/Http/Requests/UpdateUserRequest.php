<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates changes to a dashboard user. Users cannot deactivate themselves; UpdateUserAction
 * keeps the last active super-admin.
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
            'name' => ['required', 'string', 'max:255'],
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
            if (! $this->boolean('is_active') && $this->target()->is($this->user())) {
                $validator->errors()->add('is_active', __('You cannot deactivate yourself.'));
            }
        }];
    }

    private function target(): User
    {
        $target = $this->route('user');

        abort_unless($target instanceof User, 404);

        return $target;
    }
}
