<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateProjectIntegrationsSettingsRequest;
use App\Jobs\DeliverAlertJob;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Services\AlertDispatcherService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Integrations (#22), copied from externa-core: the signed outbound webhook with its
 * delivery status, the Slack and Telegram notifiers, each channel's minimum severity, events and
 * projects, a test per channel, the default severity per event, and the optional runtime alert
 * rules (#114). Secrets are write-only. The routes require `can-manage-alerts`.
 */
class ProjectIntegrationsSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(Request $request): Response
    {
        $values = $this->settings->forEditOnly([
            'webhook_url', 'has_webhook_secret', 'has_notifier_slack_webhook_url', 'has_notifier_telegram_bot_token',
            'notifier_telegram_chat_id', 'webhook_last_success', 'webhook_last_error',
        ]);

        return Inertia::render('settings/project/integrations', [
            'project' => [
                ...$values,
                'channels' => array_combine(
                    UpdateProjectIntegrationsSettingsRequest::CHANNELS,
                    array_map(fn (string $channel): array => $this->settings->alertChannelFilter($channel)->toArray(), UpdateProjectIntegrationsSettingsRequest::CHANNELS),
                ),
                'event_severities' => array_combine(
                    AlertEventEnum::severityEditableValues(),
                    array_map(fn (string $event): string => $this->settings->alertEventSeverity(AlertEventEnum::from($event))->value, AlertEventEnum::severityEditableValues()),
                ),
                'alert_cache_hit_ratio_below' => $this->settings->alertCacheHitRatioBelow(),
                'alert_delivery_failures_enabled' => $this->settings->shouldAlertDeliveryFailures(),
                'alert_log_spike_per_hour' => $this->settings->alertLogSpikePerHour(),
                'alert_ai_spend_spike_percent' => $this->settings->alertAiSpendSpikePercent(),
                'alert_ai_error_rate_above' => $this->settings->alertAiErrorRateAbove(),
                'alert_billing_webhook_failures_per_hour' => $this->settings->alertBillingWebhookFailuresPerHour(),
                'alert_billing_payment_failed_enabled' => $this->settings->shouldAlertBillingPaymentFailures(),
                'alert_auth_failed_logins_per_hour' => $this->settings->alertAuthFailedLoginsPerHour(),
                'alert_scout_index_failures_per_hour' => $this->settings->alertScoutIndexFailuresPerHour(),
            ],
            'events' => AlertEventEnum::selectableValues(),
            'severities' => SeverityEnum::values(),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'name'])->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->all(),
            'projectGroups' => ProjectGroup::query()->orderBy('name')->get(['id', 'name'])->map(fn (ProjectGroup $projectGroup): array => ['id' => $projectGroup->id, 'name' => $projectGroup->name])->all(),
        ]);
    }

    public function update(UpdateProjectIntegrationsSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        // ponytail: removes the rule rows saved before #166 on the next save; drop at 1.0.0.
        $this->settings->forget($request->staleSettingKeys());
        $this->logProjectSettingsUpdate($request, 'integrations', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('project.integrations.edit');
    }

    public function sendTest(string $channel, AlertDispatcherService $alerts): RedirectResponse
    {
        $isConfigured = match ($channel) {
            DeliverAlertJob::WEBHOOK => $this->settings->webhookUrl() !== null && $this->settings->webhookSecret() !== null,
            DeliverAlertJob::SLACK => $this->settings->slackWebhookUrl() !== null,
            default => $this->settings->telegramBotToken() !== null && $this->settings->telegramChatId() !== null,
        };

        if (! $isConfigured) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the channel settings first.')]);

            return to_route('project.integrations.edit');
        }

        $alerts->test($channel);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test event queued. It needs a queue worker.')]);

        return to_route('project.integrations.edit');
    }
}
