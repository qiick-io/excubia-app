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

    /** URL keys of the Reporting section. */
    private const URL_KEYS = ['url', 'report_issue_url', 'report_bug_url', 'report_error_url'];

    /** Number keys with their bounds: Checks and Data retention. */
    private const NUMBER_KEYS = [
        'stale_after_hours' => [1, 8760],
        'reports_kept_per_project' => [1, 1000],
        'runtime_retention_days' => [1, 3650],
        'exceptions_retention_days' => [1, 3650],
        'activity_retention_days' => [1, 3650],
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'app_name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];

        foreach (self::URL_KEYS as $key) {
            $rules[$key] = ['nullable', 'url:http,https', 'max:2048'];
        }

        foreach (self::NUMBER_KEYS as $key => [$min, $max]) {
            $rules[$key] = ['required', 'integer', "between:{$min},{$max}"];
        }

        return $rules;
    }

    /**
     * Values to store, with their final types: empty text becomes null.
     *
     * @return array<string, string|int|null>
     */
    public function settings(): array
    {
        $values = [];

        foreach (['app_name', 'description', ...self::URL_KEYS] as $key) {
            $values[$key] = $this->string($key)->trim()->toString() ?: null;
        }

        foreach (array_keys(self::NUMBER_KEYS) as $key) {
            $values[$key] = $this->integer($key);
        }

        return $values;
    }
}
