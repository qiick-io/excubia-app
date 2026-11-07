<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use App\Models\Project;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Sends an alert (#22) to the configured channels, as queued jobs: the signed webhook gets every
 * event; Slack and Telegram only the events chosen in Settings → Integrations, at or above the
 * minimum severity. Nothing is queued when no channel is configured.
 *
 * Every alert passes through here, so this is where mutes, maintenance windows and maintenance
 * mode suppress it (AlertSuppressionService) and where the alert history row is written (#114).
 * The finding fingerprints of the alert are kept on that row, for the duplicate check and the
 * health re-alert (#143).
 */
class AlertDispatcherService
{
    public function __construct(
        private SettingsRepositoryService $settings,
        private AlertSuppressionService $suppression,
    ) {}

    /**
     * @param  array<string, mixed>  $data  event details (no secrets, no user data)
     * @param  string  $summary  one line for Slack / Telegram, e.g. "laravel/framework: CVE-…"
     * @param  string|null  $subject  the issue the alert is about (finding identity, task id…), for mutes
     * @param  list<string>  $fingerprints  the core finding fingerprints the alert is about
     */
    public function dispatch(AlertEventEnum $event, Project $project, string $summary, array $data = [], ?SeverityEnum $severity = null, ?string $subject = null, array $fingerprints = []): void
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

        $reason = $this->suppression->reason($project, $event, $subject)
            ?? ($this->suppression->isDuplicate($project, $event, $subject, $fingerprints) ? AlertSuppressionService::DUPLICATE : null);
        $channels = $reason === null ? $this->channels($event, $severity) : [];

        AlertLog::query()->create([
            'uuid' => $payload['id'],
            'project_id' => $project->id,
            'event' => $event->value,
            'severity' => $severity->value,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 255),
            'summary' => mb_substr($summary, 0, 500),
            'status' => match (true) {
                $reason !== null => AlertLog::SUPPRESSED,
                $channels === [] => AlertLog::SKIPPED,
                default => AlertLog::QUEUED,
            },
            'reason' => $reason,
            'fingerprints' => $fingerprints === [] ? null : $fingerprints,
            'deliveries' => array_fill_keys($channels, AlertLog::QUEUED),
        ]);

        $text = sprintf('[%s] %s · %s: %s%s%s', Str::upper($severity->value), $event->value, $project->name, $summary, PHP_EOL, $payload['url']);

        foreach ($channels as $channel) {
            DeliverAlertJob::dispatch($channel, $payload, $channel === DeliverAlertJob::WEBHOOK ? '' : $text)->afterCommit();
        }
    }

    /**
     * The configured channels that take the event: the webhook every event, Slack and Telegram
     * the chosen events at or above the minimum severity.
     *
     * @return list<string>
     */
    private function channels(AlertEventEnum $event, SeverityEnum $severity): array
    {
        $isNotified = in_array($event->value, $this->settings->notifierEventTypes(), true)
            && $severity->rank() <= $this->settings->notifierMinimumSeverity()->rank();

        return array_keys(array_filter([
            DeliverAlertJob::WEBHOOK => $this->settings->webhookUrl() !== null && $this->settings->webhookSecret() !== null,
            DeliverAlertJob::SLACK => $isNotified && $this->settings->slackWebhookUrl() !== null,
            DeliverAlertJob::TELEGRAM => $isNotified && $this->settings->telegramBotToken() !== null && $this->settings->telegramChatId() !== null,
        ]));
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
