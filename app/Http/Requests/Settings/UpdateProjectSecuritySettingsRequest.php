<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the Authentication & security page (password policy, login attempts, required 2FA,
 * SSO). An empty client secret keeps the stored one.
 */
class UpdateProjectSecuritySettingsRequest extends FormRequest
{
    use AuthorizesProjectSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password_policy' => ['required', Rule::in(SettingsRepositoryService::PASSWORD_POLICIES)],
            'login_max_attempts' => ['required', 'integer', 'between:1,100'],
            'two_factor_required' => ['required', 'boolean'],
            'oidc_enabled' => ['boolean'],
            'oidc_issuer' => ['nullable', 'required_if_accepted:oidc_enabled', 'url:http,https', 'max:2048'],
            'oidc_client_id' => ['nullable', 'required_if_accepted:oidc_enabled', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:2048'],
            'oidc_button_label' => ['nullable', 'string', 'max:100'],
            'oidc_jit_provisioning' => ['boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('oidc_enabled')
                && ! $this->filled('oidc_client_secret')
                && app(SettingsRepositoryService::class)->oidcClientSecret() === null) {
                $validator->errors()->add('oidc_client_secret', __('The client secret is required to enable SSO.'));
            }
        }];
    }

    /**
     * Values to store, with their final types.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'password_policy' => $this->string('password_policy')->toString(),
            'login_max_attempts' => $this->integer('login_max_attempts'),
            'two_factor_required' => $this->boolean('two_factor_required'),
            'oidc_enabled' => $this->boolean('oidc_enabled'),
            'oidc_issuer' => $this->string('oidc_issuer')->trim()->toString() ?: null,
            'oidc_client_id' => $this->string('oidc_client_id')->trim()->toString() ?: null,
            'oidc_client_secret' => $this->string('oidc_client_secret')->toString() ?: null,
            'oidc_button_label' => $this->string('oidc_button_label')->trim()->toString() ?: null,
            'oidc_jit_provisioning' => $this->boolean('oidc_jit_provisioning'),
        ];
    }
}
