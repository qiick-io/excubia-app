<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\IssueAlertWebhookSecretAction;
use App\Actions\SaveAlertWebhookAction;
use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateProjectIntegrationsSettingsRequest;
use App\Jobs\DeliverAlertJob;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Services\AlertDispatcherService;
use App\Services\AlertWebhookService;
use App\Services\ApiKeySecretOnceService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Settings → Integrations (#22), copied from externa-core: the signed outbound webhook with its
 * delivery status (qiick/laravel-outbound: a generated signing secret shown once, re-enable after
 * an automatic disable), the Slack and Telegram notifiers, each channel's minimum severity, events
 * and projects, a test per channel, the default severity per event, and the optional runtime alert
 * rules (#114). Secrets are write-only. The routes require `can-manage-alerts`.
 */
class ProjectIntegrationsSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly AlertWebhookService $webhooks,
    ) {}

    public function edit(Request $request, ApiKeySecretOnceService $secretOnce): SymfonyResponse
    {
        $values = $this->settings->forEditOnly([
            'has_notifier_slack_webhook_url', 'has_notifier_telegram_bot_token', 'notifier_telegram_chat_id',
        ]);
        $page = Inertia::render('settings/project/integrations', [
            'project' => [
                ...$values,
                ...$this->webhookStatus(),
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

        // Only a full page visit takes the one-time secret: a partial reload must not consume it.
        $issuedSecret = $secretOnce->isShowingRequest($request, 'settings/project/integrations')
            ? $secretOnce->pull($request->session(), ApiKeySecretOnceService::WEBHOOK_SESSION_KEY)
            : null;

        return $secretOnce->render(
            $request,
            $page,
            'issuedWebhookSecret',
            $issuedSecret === null ? null : ['secret' => $issuedSecret['secret'], 'reason' => $issuedSecret['reason']],
        );
    }

    public function update(UpdateProjectIntegrationsSettingsRequest $request, SaveAlertWebhookAction $saveAlertWebhook): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $saveAlertWebhook->execute($request->webhookUrl(), $this->actor($request));
        // ponytail: removes the rule rows saved before #166 on the next save; drop at 1.0.0.
        $this->settings->forget($request->staleSettingKeys());
        $this->logProjectSettingsUpdate($request, 'integrations', [...$values, 'webhook_url' => $request->webhookUrl()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('project.integrations.edit');
    }

    public function sendTest(string $channel, AlertDispatcherService $alerts): RedirectResponse
    {
        $isConfigured = match ($channel) {
            DeliverAlertJob::WEBHOOK => $this->webhooks->isReady(),
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

    /**
     * Generates the webhook's signing secret, or rotates it (the previous one keeps signing for
     * 24 h), and hands it to the next page load once.
     */
    public function issueWebhookSecret(Request $request, IssueAlertWebhookSecretAction $issueSecret, ApiKeySecretOnceService $secretOnce): RedirectResponse
    {
        $issued = $issueSecret->execute($this->actor($request));

        if ($issued === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the webhook URL first.')]);

            return to_route('project.integrations.edit');
        }

        $secretOnce->store($request->session(), $issued['secret'], 'webhook', $issued['reason'], sessionKey: ApiKeySecretOnceService::WEBHOOK_SESSION_KEY);

        return to_route('project.integrations.edit');
    }

    /**
     * Turns the webhook back on after qiick/laravel-outbound disabled it (410 Gone, or days of failures).
     */
    public function enableWebhook(WebhookDeliveryCoordinator $coordinator): RedirectResponse
    {
        $endpoint = $this->webhooks->endpoint();

        if ($endpoint !== null) {
            $coordinator->enableEndpoint($endpoint);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook enabled.')]);

        return to_route('project.integrations.edit');
    }

    /**
     * The signed-in user: the routes sit behind `auth`, so a missing one is refused, never guessed.
     */
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * The webhook's URL and delivery status for the page, from qiick/laravel-outbound's endpoint.
     *
     * @return array{webhook_url: string|null, has_webhook_secret: bool, webhook_status: array{is_active: bool, disabled_reason: string|null, last_success_at: string|null, last_failure_at: string|null, consecutive_failures: int}|null}
     */
    private function webhookStatus(): array
    {
        $endpoint = $this->webhooks->endpoint();

        return [
            'webhook_url' => $endpoint?->url,
            'has_webhook_secret' => $this->webhooks->isReady() || ($endpoint !== null && ! $endpoint->is_active),
            'webhook_status' => $endpoint === null ? null : [
                'is_active' => $endpoint->is_active,
                'disabled_reason' => $endpoint->disabled_reason?->value,
                'last_success_at' => $endpoint->last_success_at?->toIso8601String(),
                'last_failure_at' => $endpoint->last_failure_at?->toIso8601String(),
                'consecutive_failures' => $endpoint->consecutive_failures,
            ],
        ];
    }
}
