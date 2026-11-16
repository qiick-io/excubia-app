<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → AI: which tools the assistant and the MCP server may use, read and write (#320).
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
     * Stores the tools left unticked, so a tool added by a later upgrade is on (#320).
     *
     * @return array{ai_disabled_tools: list<string>}
     */
    public function settings(): array
    {
        $enabled = array_map('strval', (array) $this->input('tools', []));

        return ['ai_disabled_tools' => array_values(array_diff(array_keys(ExcubiaAssistantAgent::TOOLS), $enabled))];
    }
}
