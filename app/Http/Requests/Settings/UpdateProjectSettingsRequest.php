<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the General settings page: application name, bug report link and Checks.
 */
class UpdateProjectSettingsRequest extends FormRequest
{
    use AuthorizesProjectSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'app_name' => ['nullable', 'string', 'max:100'],
            'report_bug_url' => ['nullable', 'url:http,https', 'max:2048'],
            'reports_kept_per_project' => ['required', 'integer', 'between:1,1000'],
            'stale_after_hours' => ['required', 'integer', 'between:1,8760'],
        ];
    }

    /**
     * Values to store, with their final types.
     *
     * @return array{app_name: string|null, report_bug_url: string|null, reports_kept_per_project: int, stale_after_hours: int}
     */
    public function settings(): array
    {
        return [
            'app_name' => $this->string('app_name')->trim()->toString() ?: null,
            'report_bug_url' => $this->string('report_bug_url')->trim()->toString() ?: null,
            'reports_kept_per_project' => $this->integer('reports_kept_per_project'),
            'stale_after_hours' => $this->integer('stale_after_hours'),
        ];
    }
}
