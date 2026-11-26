<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\SetupTokenService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates the admin step of the first-run wizard (#116). Allowed only while no user exists:
 * once an admin is there, the wizard is locked (403).
 */
class StoreFirstAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A fresh install is public: only the holder of the one-time setup link may claim it.
        return ! User::query()->exists() && app(SetupTokenService::class)->isValid($this->input('setup_token'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'setup_token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::default()],
        ];
    }
}
