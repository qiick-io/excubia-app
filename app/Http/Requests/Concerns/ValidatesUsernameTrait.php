<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared username validation for every surface that creates or edits a user. The value is
 * canonicalized before the rules run so the unique check is case-insensitive on every database.
 */
trait ValidatesUsernameTrait
{
    /**
     * Canonicalize the submitted username before validation; non-strings are left to the rules.
     */
    protected function prepareForValidation(): void
    {
        $username = $this->input('username');

        if (is_string($username)) {
            $this->merge(['username' => User::normalizeUsername($username)]);
        }
    }

    /**
     * The fleet username rules: lowercase letters, digits and . _ - between alphanumeric ends.
     * The current user is ignored on updates, so saving their own username stays valid.
     *
     * @return array<int, Unique|string>
     */
    protected function usernameRules(?int $userId = null): array
    {
        return [
            'nullable',
            'string',
            'min:3',
            'max:32',
            'regex:/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/',
            $userId === null
                ? Rule::unique(User::class, 'username')
                : Rule::unique(User::class, 'username')->ignore($userId),
        ];
    }
}
