<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → AI: which read-only tools the assistant may use.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    use AuthorizesProjectSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tools' => ['present', 'array'],
            'tools.*' => ['string', 'distinct', Rule::in(array_keys(ExcubiaAssistantAgent::TOOLS))],
        ];
    }

    /**
     * @return array{ai_enabled_tools: list<string>}
     */
    public function settings(): array
    {
        return ['ai_enabled_tools' => array_values(array_map('strval', (array) $this->input('tools', [])))];
    }
}
