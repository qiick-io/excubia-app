<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Localization settings page: the default UI language.
 */
class UpdateProjectLocalizationSettingsRequest extends FormRequest
{
    use AuthorizesProjectSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_language' => ['required', 'string', Rule::in(array_keys((array) config('i18n.available_locales', ['en' => 'English'])))],
        ];
    }

    /**
     * @return array{default_language: string}
     */
    public function settings(): array
    {
        return ['default_language' => $this->string('default_language')->toString()];
    }
}
