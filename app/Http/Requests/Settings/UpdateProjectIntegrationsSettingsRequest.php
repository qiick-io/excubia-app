<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Http\Requests\Settings\Concerns\ValidatesAlertFiltersTrait;
use App\Jobs\DeliverAlertJob;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → Integrations (#22), as externa-core's: the signed outbound webhook and the Slack /
 * Telegram notifiers with their own filters (`channels.<channel>`), the default severity per
 * event (`event_severities`), the optional runtime alert rules (#114) and AI usage rules (#129). Empty secrets keep the
 * stored ones. Needs `can-manage-alerts`.
 */
class UpdateProjectIntegrationsSettingsRequest extends FormRequest
{
    use ValidatesAlertFiltersTrait;

    /** The channels with their own filters; email subscribers set theirs in their profile. */
    public const CHANNELS = [DeliverAlertJob::WEBHOOK, DeliverAlertJob::SLACK, DeliverAlertJob::TELEGRAM];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageAlerts', Setting::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $projectIds = Project::query()->visibleTo($this->user())->pluck('id')->all();
        $filterRules = array_merge(...array_map(
            fn (string $channel): array => $this->alertFilterRules("channels.{$channel}", $projectIds),
            self::CHANNELS,
        ));

        return [
            ...$filterRules,
            // Keyed by event; events that take a finding's severity are dropped in settings().
            'event_severities' => ['nullable', 'array'],
            'event_severities.*' => [Rule::enum(SeverityEnum::class)],
            'webhook_url' => ['nullable', 'url:https', 'max:2048'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:512'],
            'notifier_slack_webhook_url' => ['nullable', 'url:https', 'starts_with:https://hooks.slack.com/', 'max:2048'],
            // BotFather token shape: "<bot_id>:<secret>".
            'notifier_telegram_bot_token' => ['nullable', 'string', 'max:512', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'notifier_telegram_chat_id' => ['nullable', 'string', 'max:64', 'regex:/^-?\d+$|^@[A-Za-z0-9_]{5,}$/'],
            // Optional runtime alert rules (#107, #114); empty turns a rule off.
            'alert_cache_hit_ratio_below' => ['nullable', 'integer', 'min:1', 'max:100'],
            'alert_delivery_failures_enabled' => ['sometimes', 'boolean'],
            'alert_log_spike_per_hour' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            // AI usage rules (#129); empty turns a rule off.
            'alert_ai_spend_spike_percent' => ['nullable', 'integer', 'min:101', 'max:100000'],
            'alert_ai_error_rate_above' => ['nullable', 'integer', 'min:1', 'max:99'],
            // Billing webhook rules (#178); empty / unchecked turns a rule off.
            'alert_billing_webhook_failures_per_hour' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'alert_billing_payment_failed_enabled' => ['sometimes', 'boolean'],
            // Failed-login rule (#180); empty turns it off.
            'alert_auth_failed_logins_per_hour' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * The validated values to save, keyed by setting: the channel filters go in `alert_channels`
     * and the event severities in `alert_event_severities`, never as their rule keys.
     *
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
            'alert_channels' => array_combine(self::CHANNELS, array_map(fn (string $channel): array => $this->alertFilter("channels.{$channel}")->toArray(), self::CHANNELS)),
            'alert_event_severities' => array_intersect_key(
                array_map('strval', (array) $this->input('event_severities', [])),
                array_flip(AlertEventEnum::severityEditableValues()),
            ),
            'alert_cache_hit_ratio_below' => $this->filled('alert_cache_hit_ratio_below') ? $this->integer('alert_cache_hit_ratio_below') : null,
            'alert_delivery_failures_enabled' => $this->boolean('alert_delivery_failures_enabled'),
            'alert_log_spike_per_hour' => $this->filled('alert_log_spike_per_hour') ? $this->integer('alert_log_spike_per_hour') : null,
            'alert_ai_spend_spike_percent' => $this->filled('alert_ai_spend_spike_percent') ? $this->integer('alert_ai_spend_spike_percent') : null,
            'alert_ai_error_rate_above' => $this->filled('alert_ai_error_rate_above') ? $this->integer('alert_ai_error_rate_above') : null,
            'alert_billing_webhook_failures_per_hour' => $this->filled('alert_billing_webhook_failures_per_hour') ? $this->integer('alert_billing_webhook_failures_per_hour') : null,
            'alert_billing_payment_failed_enabled' => $this->boolean('alert_billing_payment_failed_enabled'),
            'alert_auth_failed_logins_per_hour' => $this->filled('alert_auth_failed_logins_per_hour') ? $this->integer('alert_auth_failed_logins_per_hour') : null,
        ];
    }

    /**
     * The rule keys that are not settings (`channels.slack.minimum_severity`, `event_severities`).
     * Before #166, settings() saved them with their rule arrays as values; nothing reads them.
     *
     * @return list<string>
     */
    public function staleSettingKeys(): array
    {
        return array_values(array_diff(array_keys($this->rules()), array_keys($this->settings())));
    }
}
