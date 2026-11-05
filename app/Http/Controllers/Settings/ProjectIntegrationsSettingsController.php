<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateProjectIntegrationsSettingsRequest;
use App\Jobs\DeliverAlertJob;
use App\Services\AlertDispatcherService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Integrations (#22), copied from externa-core: the signed outbound webhook with its
 * delivery status, the Slack and Telegram notifiers, the events they send and a test per
 * channel. Secrets are write-only. The routes require `can-manage-project-settings`.
 */
class ProjectIntegrationsSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        $values = $this->settings->forEditOnly([
            'webhook_url', 'has_webhook_secret', 'notifier_slack_webhook_url', 'has_notifier_telegram_bot_token',
            'notifier_telegram_chat_id', 'webhook_last_success', 'webhook_last_error',
        ]);

        return Inertia::render('settings/project/integrations', [
            'project' => [
                ...$values,
                'notifier_event_types' => $this->settings->notifierEventTypes(),
                'notifier_minimum_severity' => $this->settings->notifierMinimumSeverity()->value,
            ],
            'events' => AlertEventEnum::selectableValues(),
            'severities' => SeverityEnum::values(),
        ]);
    }

    public function update(UpdateProjectIntegrationsSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
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
