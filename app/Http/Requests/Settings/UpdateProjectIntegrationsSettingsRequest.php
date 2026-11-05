<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Http\Requests\Settings\Concerns\AuthorizesProjectSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → Integrations (#22), as externa-core's: the signed outbound webhook and the Slack /
 * Telegram notifiers. Empty secrets keep the stored ones.
 */
class UpdateProjectIntegrationsSettingsRequest extends FormRequest
{
    use AuthorizesProjectSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'webhook_url' => ['nullable', 'url:https', 'max:2048'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:512'],
            'notifier_slack_webhook_url' => ['nullable', 'url:https', 'starts_with:https://hooks.slack.com/', 'max:2048'],
            // BotFather token shape: "<bot_id>:<secret>".
            'notifier_telegram_bot_token' => ['nullable', 'string', 'max:512', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'notifier_telegram_chat_id' => ['nullable', 'string', 'max:64', 'regex:/^-?\d+$|^@[A-Za-z0-9_]{5,}$/'],
            // Missing when every box is cleared: no event for Slack and Telegram.
            'notifier_event_types' => ['nullable', 'array'],
            'notifier_event_types.*' => ['string', 'distinct', Rule::in(AlertEventEnum::selectableValues())],
            'notifier_minimum_severity' => ['required', Rule::enum(SeverityEnum::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $text = fn (string $key): ?string => $this->string($key)->trim()->toString() ?: null;

        return [
            'webhook_url' => $text('webhook_url'),
            'webhook_secret' => $text('webhook_secret'),
            'notifier_slack_webhook_url' => $text('notifier_slack_webhook_url'),
            'notifier_telegram_bot_token' => $text('notifier_telegram_bot_token'),
            'notifier_telegram_chat_id' => $text('notifier_telegram_chat_id'),
            'notifier_event_types' => array_values(array_map('strval', (array) $this->input('notifier_event_types', []))),
            'notifier_minimum_severity' => $this->string('notifier_minimum_severity')->toString(),
        ];
    }
}
