<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the application settings page (General, Security, Checks).
 */
class UpdateApplicationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Setting::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'app_name' => ['nullable', 'string', 'max:100'],
            'password_policy' => ['required', Rule::in(SettingsRepositoryService::PASSWORD_POLICIES)],
            'login_max_attempts' => ['required', 'integer', 'between:1,100'],
            'two_factor_required' => ['required', 'boolean'],
            'reports_kept_per_project' => ['required', 'integer', 'between:1,1000'],
            'stale_after_hours' => ['required', 'integer', 'between:1,8760'],
        ];
    }

    /**
     * Values to store, with their final types.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'app_name' => $this->string('app_name')->trim()->toString() ?: null,
            'password_policy' => $this->string('password_policy')->toString(),
            'login_max_attempts' => $this->integer('login_max_attempts'),
            'two_factor_required' => $this->boolean('two_factor_required'),
            'reports_kept_per_project' => $this->integer('reports_kept_per_project'),
            'stale_after_hours' => $this->integer('stale_after_hours'),
        ];
    }
}
