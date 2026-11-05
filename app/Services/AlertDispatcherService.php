<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\Project;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Sends an alert (#22) to the configured channels, as queued jobs: the signed webhook gets every
 * event; Slack and Telegram only the events chosen in Settings → Integrations, at or above the
 * minimum severity. Nothing is queued when no channel is configured.
 */
class AlertDispatcherService
{
    public function __construct(private SettingsRepositoryService $settings) {}

    /**
     * @param  array<string, mixed>  $data  event details (no secrets, no user data)
     * @param  string  $summary  one line for Slack / Telegram, e.g. "laravel/framework: CVE-…"
     */
    public function dispatch(AlertEventEnum $event, Project $project, string $summary, array $data = [], ?SeverityEnum $severity = null): void
    {
        $severity ??= $event->defaultSeverity();
        $payload = [
            'id' => (string) Str::uuid(),
            'event' => $event->value,
            'severity' => $severity->value,
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'occurredAt' => Date::now()->toIso8601ZuluString(),
            'url' => route('projects.show', $project->slug),
            'data' => (object) $data,
        ];

        if ($this->settings->webhookUrl() !== null && $this->settings->webhookSecret() !== null) {
            DeliverAlertJob::dispatch(DeliverAlertJob::WEBHOOK, $payload)->afterCommit();
        }

        $isNotified = in_array($event->value, $this->settings->notifierEventTypes(), true)
            && $severity->rank() <= $this->settings->notifierMinimumSeverity()->rank();

        if (! $isNotified) {
            return;
        }

        $text = sprintf('[%s] %s · %s: %s%s%s', Str::upper($severity->value), $event->value, $project->name, $summary, PHP_EOL, $payload['url']);

        if ($this->settings->slackWebhookUrl() !== null) {
            DeliverAlertJob::dispatch(DeliverAlertJob::SLACK, $payload, $text)->afterCommit();
        }

        if ($this->settings->telegramBotToken() !== null && $this->settings->telegramChatId() !== null) {
            DeliverAlertJob::dispatch(DeliverAlertJob::TELEGRAM, $payload, $text)->afterCommit();
        }
    }

    /**
     * A test event on one channel, sent whatever the event and severity filters say.
     */
    public function test(string $channel): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'event' => AlertEventEnum::Ping->value,
            'severity' => SeverityEnum::Info->value,
            'project' => null,
            'occurredAt' => Date::now()->toIso8601ZuluString(),
            'url' => route('dashboard'),
            'data' => (object) [],
        ];

        DeliverAlertJob::dispatch($channel, $payload, 'Excubia test notification: the channel works.');
    }
}
