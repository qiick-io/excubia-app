<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\AlertFilterData;
use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends an alert (#22) to the configured channels, as queued jobs: the signed webhook, Slack and
 * Telegram each take the events, minimum severity and projects chosen for them in Settings →
 * Integrations; every user who turned alert emails on gets a mail when their own filters take
 * it and they can see the project. Nothing is queued when no channel takes the alert. An event
 * without a finding severity takes the admin's severity for it, else its default.
 *
 * Every alert passes through here, so this is where mutes, maintenance windows and maintenance
 * mode suppress it (AlertSuppressionService) and where the alert history row is written (#114).
 * The finding fingerprints of the alert are kept on that row, for the duplicate check and the
 * health re-alert (#143).
 *
 * Inside grouped(), the alerts raised together (one report push) go out as one Slack, Telegram
 * and email message per channel instead of one per alert; the webhook still gets one signed
 * request per alert, so its payload and event ids stay as they are. Every alert keeps its own
 * history row.
 */
class AlertDispatcherService
{
    /** Lines listed in one grouped Slack or Telegram message; Telegram caps a message at 4096 characters. */
    public const GROUPED_LINES = 15;

    /**
     * The messages held back by grouped(), per channel.
     *
     * @var array<string, non-empty-list<array{payload: array<string, mixed>, summary: string}>>|null
     */
    private ?array $group = null;

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
        $severity ??= $this->settings->alertEventSeverity($event);
        $payload = [
            'id' => (string) Str::uuid(),
            'event' => $event->value,
            'severity' => $severity->value,
            'project' => ['name' => $project->name, 'slug' => $project->slug],
            'occurredAt' => Date::now()->toIso8601ZuluString(),
            // A deleted project has no page any more.
            'url' => $event === AlertEventEnum::ProjectDeleted ? route('dashboard') : route('projects.show', $project->slug),
            'data' => (object) $data,
        ];

        $reason = $this->suppression->reason($project, $event, $subject)
            ?? ($this->suppression->isDuplicate($project, $event, $subject, $fingerprints) ? AlertSuppressionService::DUPLICATE : null)
            ?? ($this->suppression->isThrottled($project, $event, $subject, $severity, $fingerprints) ? AlertSuppressionService::THROTTLED : null);
        $channels = $reason === null ? $this->channels($event, $severity, $project) : [];

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

        foreach ($channels as $channel) {
            if ($this->group !== null && $channel !== DeliverAlertJob::WEBHOOK) {
                $this->group[$channel][] = ['payload' => $payload, 'summary' => $summary];

                continue;
            }

            $this->queue($channel, [['payload' => $payload, 'summary' => $summary]]);
        }
    }

    /**
     * Runs `$callback` and sends the alerts it dispatches together: one message per Slack,
     * Telegram and email channel. Nested calls join the outer group. Nothing is sent when the
     * callback throws (its history rows roll back with the caller's transaction).
     *
     * @param  callable(): void  $callback
     */
    public function grouped(callable $callback): void
    {
        if ($this->group !== null) {
            $callback();

            return;
        }

        $this->group = [];

        try {
            $callback();
        } catch (Throwable $exception) {
            $this->takeGroup();

            throw $exception;
        }

        foreach ($this->takeGroup() as $channel => $alerts) {
            $this->queue($channel, $alerts);
        }
    }

    /**
     * Ends grouping and returns the messages it held back.
     *
     * @return array<string, non-empty-list<array{payload: array<string, mixed>, summary: string}>>
     */
    private function takeGroup(): array
    {
        $group = $this->group ?? [];
        $this->group = null;

        return $group;
    }

    /**
     * Queues one message to one channel. A single alert keeps its own text; several alerts are
     * listed worst first, under the worst one's payload, and the job records the outcome on
     * every alert's history row.
     *
     * @param  non-empty-list<array{payload: array<string, mixed>, summary: string}>  $alerts
     */
    private function queue(string $channel, array $alerts): void
    {
        $isEmail = DeliverAlertJob::emailUserId($channel) !== null;

        if (count($alerts) === 1) {
            ['payload' => $payload, 'summary' => $summary] = $alerts[0];
            $body = match (true) {
                $channel === DeliverAlertJob::WEBHOOK => '',
                $isEmail => $summary,
                default => $this->line($payload, $summary, true).PHP_EOL.$payload['url'],
            };

            DeliverAlertJob::dispatch($channel, $payload, $body)->afterCommit();

            return;
        }

        usort($alerts, fn (array $first, array $second): int => SeverityEnum::from($first['payload']['severity'])->rank() <=> SeverityEnum::from($second['payload']['severity'])->rank());
        $lead = $alerts[0]['payload'];
        $items = array_map(fn (array $alert): array => [
            'id' => (string) $alert['payload']['id'],
            'event' => (string) $alert['payload']['event'],
            'severity' => (string) $alert['payload']['severity'],
            'summary' => $alert['summary'],
        ], $alerts);

        $text = '';

        if (! $isEmail) {
            $lines = array_map(fn (array $alert): string => $this->line($alert['payload'], Str::limit($alert['summary'], 200), false), array_slice($alerts, 0, self::GROUPED_LINES));
            $hidden = count($alerts) - count($lines);
            $text = implode(PHP_EOL, [
                sprintf('[%s] %s: %d alerts', Str::upper((string) $lead['severity']), $lead['project']['name'], count($alerts)),
                ...$lines,
                ...($hidden > 0 ? ["… and {$hidden} more"] : []),
                (string) $lead['url'],
            ]);
        }

        DeliverAlertJob::dispatch($channel, $lead, $text, $items)->afterCommit();
    }

    /**
     * `[HIGH] finding.new · Shop: summary`, or without the project inside a grouped message.
     *
     * @param  array<string, mixed>  $payload
     */
    private function line(array $payload, string $summary, bool $hasProject): string
    {
        $project = $hasProject ? ' · '.$payload['project']['name'] : '';

        return sprintf('[%s] %s%s: %s', Str::upper((string) $payload['severity']), $payload['event'], $project, $summary);
    }

    /**
     * The configured channels whose filters take the alert, then one `email:<user id>` channel
     * per subscribed, active and verified user whose filters take it and who can see the project.
     *
     * @return list<string>
     */
    private function channels(AlertEventEnum $event, SeverityEnum $severity, Project $project): array
    {
        $isConfigured = [
            DeliverAlertJob::WEBHOOK => $this->settings->webhookUrl() !== null && $this->settings->webhookSecret() !== null,
            DeliverAlertJob::SLACK => $this->settings->slackWebhookUrl() !== null,
            DeliverAlertJob::TELEGRAM => $this->settings->telegramBotToken() !== null && $this->settings->telegramChatId() !== null,
        ];
        $channels = array_keys(array_filter(
            $isConfigured,
            fn (bool $isReady, string $channel): bool => $isReady && $this->settings->alertChannelFilter($channel)->accepts($event, $severity, $project),
            ARRAY_FILTER_USE_BOTH,
        ));

        // ponytail: one policy check per subscriber; fine for a self-hosted team, cache the
        // visible project ids per user if installs get hundreds of subscribers.
        $subscribers = User::query()
            ->where('is_active', true)
            ->where('has_alert_emails', true)
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => AlertFilterData::fromStored($user->alert_email_filters, SeverityEnum::High)->accepts($event, $severity, $project)
                && $user->can('view', $project));

        foreach ($subscribers as $user) {
            $channels[] = DeliverAlertJob::EMAIL.':'.$user->id;
        }

        return $channels;
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
