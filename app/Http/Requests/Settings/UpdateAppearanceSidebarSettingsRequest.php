<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Sidebar page: module order and visibility. The form posts the list as JSON.
 */
class UpdateAppearanceSidebarSettingsRequest extends FormRequest
{
    use AuthorizesProjectSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sidebar_modules' => ['required', 'array', 'min:1'],
            'sidebar_modules.*' => ['required', 'array:id,enabled,locked'],
            'sidebar_modules.*.id' => ['required', 'string', 'distinct', Rule::in(app(SettingsRepositoryService::class)->sidebarModuleIds())],
            'sidebar_modules.*.enabled' => ['required', 'boolean'],
            'sidebar_modules.*.locked' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Modules in the submitted order; `locked` comes from the service, never from the client.
     *
     * @return array{sidebar_modules: list<array{id: string, enabled: bool}>}
     */
    public function settings(): array
    {
        /** @var list<array{id: string, enabled: bool|int|string}> $modules */
        $modules = $this->validated('sidebar_modules');

        return [
            'sidebar_modules' => array_map(fn (array $module): array => [
                'id' => $module['id'],
                'enabled' => filter_var($module['enabled'], FILTER_VALIDATE_BOOLEAN),
            ], $modules),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('sidebar_modules'))) {
            return;
        }

        $decoded = json_decode($this->string('sidebar_modules')->toString(), true);

        if (is_array($decoded)) {
            $this->merge(['sidebar_modules' => $decoded]);
        }
    }
}
