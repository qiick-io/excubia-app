<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RecordAlertDeliveryAction;
use App\Mail\AlertMail;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\AlertWebhookService;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Qiick\Outbound\Http\HttpRequestOptions;
use Qiick\Outbound\Http\OutboundHttpException;
use Qiick\Outbound\Http\OutboundRequest;
use Qiick\Outbound\Http\PinnedHttpClient;
use Qiick\Outbound\Ssrf\RemoteUrlGuard;
use Qiick\Outbound\Ssrf\UnsafeUrl;
use RuntimeException;
use Throwable;

/**
 * Delivers one alert (#22) to one channel, as externa-core's notifier jobs. The signed webhook
 * channel is not here: qiick/laravel-outbound delivers it ({@see AlertWebhookService}).
 * - slack: an incoming-webhook message;
 * - telegram: a Bot API sendMessage;
 * - email:<user id>: an AlertMail to that user, in their locale (the user must still be active);
 * - app:<user id>: an in-app AlertNotification for that user's bell (#296), same condition.
 * HTTPS only, no redirects, and hosts that resolve to private or reserved addresses are refused
 * by {@see RemoteUrlGuard} through {@see PinnedHttpClient}; the connection
 * is pinned with CURLOPT_CONNECT_TO to the checked address, and transport errors never quote the URL.
 * Retried 3 times with backoff; every channel's outcome goes on the alert's history row (#114). A grouped message (one
 * report push, #22) carries the worst alert's payload and lists every alert in `alerts`, whose
 * history rows all get the outcome.
 */
class DeliverAlertJob implements ShouldQueue
{
    use Queueable;

    /** The signed webhook's channel name (filters, history); qiick/laravel-outbound delivers it. */
    public const WEBHOOK = 'webhook';

    public const SLACK = 'slack';

    public const TELEGRAM = 'telegram';

    /** Prefix of the per-user email channel, `email:<user id>`. */
    public const EMAIL = 'email';

    /** Prefix of the per-user in-app channel, `app:<user id>` (#296). */
    public const APP = 'app';

    public int $tries = 3;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $payload  see AlertDispatcherService
     * @param  list<array{id: string, event: string, severity: string, summary: string}>  $alerts  every alert of a grouped message, worst first; empty for one alert
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $payload,
        public readonly string $text = '',
        public readonly array $alerts = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @throws RuntimeException when the endpoint does not answer 2xx (the job is retried)
     */
    public function handle(
        SettingsRepositoryService $settings,
        PinnedHttpClient $http,
    ): void {
        $userId = self::emailUserId($this->channel);

        if ($userId !== null) {
            $this->sendMail($settings, $userId);

            return;
        }

        $userId = self::appUserId($this->channel);

        if ($userId !== null) {
            $this->storeNotification($settings, $userId);

            return;
        }

        try {
            match ($this->channel) {
                self::SLACK => $this->deliverRaw(
                    $settings,
                    $http,
                    $settings->slackWebhookUrl(),
                    (string) json_encode(['text' => $this->text], JSON_THROW_ON_ERROR),
                ),
                self::TELEGRAM => $this->deliverRaw(
                    $settings,
                    $http,
                    'https://api.telegram.org/bot'.$settings->telegramBotToken().'/sendMessage',
                    (string) json_encode([
                        'chat_id' => $settings->telegramChatId(),
                        'text' => $this->text,
                        'disable_web_page_preview' => true,
                    ], JSON_THROW_ON_ERROR),
                ),
                default => $this->record($settings, false, 'Refused: the URL is missing, not HTTPS or points to a private address.'),
            };
        } catch (UnsafeUrl $exception) {
            $this->record($settings, false, 'Refused: '.$exception->reason());
        }
    }

    /**
     * The user id of an `email:<user id>` channel, else null.
     */
    public static function emailUserId(string $channel): ?int
    {
        return self::userId(self::EMAIL, $channel);
    }

    /**
     * The user id of an `app:<user id>` channel, else null.
     */
    public static function appUserId(string $channel): ?int
    {
        return self::userId(self::APP, $channel);
    }

    /**
     * The user id of a per-user channel (`<prefix>:<user id>`), else null.
     */
    private static function userId(string $prefix, string $channel): ?int
    {
        return preg_match('/^'.$prefix.':(\d+)$/', $channel, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Mails the alert to one subscriber, synchronously inside this queued job, so the outcome
     * can be recorded and a transport error retried.
     */
    private function sendMail(SettingsRepositoryService $settings, int $userId): void
    {
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        if ($user === null) {
            $this->record($settings, false, 'Refused: the user is gone or disabled.');

            return;
        }

        Mail::to($user)->locale($user->locale)->send(new AlertMail($this->payload, $this->text, $this->alerts));
        $this->record($settings, true, 'sent');
    }

    /**
     * Stores the in-app notification (a `notifications` row) for one user, synchronously inside
     * this queued job like the mail, so the outcome lands on the alert's history row.
     */
    private function storeNotification(SettingsRepositoryService $settings, int $userId): void
    {
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        if ($user === null) {
            $this->record($settings, false, 'Refused: the user is gone or disabled.');

            return;
        }

        $user->notifyNow(new AlertNotification($this->payload, $this->text, $this->alerts));
        $this->record($settings, true, 'stored');
    }

    public function failed(Throwable $exception): void
    {
        $this->record(app(SettingsRepositoryService::class), false, $exception->getMessage());
    }

    /**
     * Posts a raw JSON body to a Slack or Telegram URL through the pinned client.
     *
     * @throws UnsafeUrl
     * @throws RuntimeException
     */
    private function deliverRaw(
        SettingsRepositoryService $settings,
        PinnedHttpClient $http,
        ?string $url,
        string $body,
    ): void {
        if ($url === null) {
            $this->record($settings, false, 'Refused: the URL is missing, not HTTPS or points to a private address.');

            return;
        }

        $this->post($http, $url, $body, ['User-Agent' => 'Excubia-Alerts/1.0'], $settings);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws UnsafeUrl
     * @throws RuntimeException
     */
    private function post(
        PinnedHttpClient $http,
        string $url,
        string $body,
        array $headers,
        SettingsRepositoryService $settings,
    ): void {
        try {
            $response = $http->send(
                'POST',
                $url,
                static function (OutboundRequest $request) use ($body, $headers): void {
                    $request->withHeaders($headers)->withBody($body, 'application/json');
                },
                new HttpRequestOptions(timeoutSeconds: 5, connectTimeoutSeconds: 3),
            );
        } catch (OutboundHttpException $exception) {
            // Transport errors quote the URL, which holds the Slack webhook secret or the
            // Telegram bot token: never let it reach failed_jobs, the logs or Settings (#51).
            throw new RuntimeException("Alert delivery to {$this->channel} failed: ".class_basename($exception->getPrevious() ?? $exception));
        } catch (Throwable $exception) {
            if ($exception instanceof UnsafeUrl || $exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException("Alert delivery to {$this->channel} failed: ".class_basename($exception));
        }

        if (! $response->successful()) {
            throw new RuntimeException("Alert delivery to {$this->channel} failed: HTTP {$response->status()}");
        }

        $this->record($settings, true, "HTTP {$response->status()}");
    }

    /**
     * Keeps the outcome on the alert's history row.
     */
    private function record(SettingsRepositoryService $settings, bool $isSuccess, string $message): void
    {
        $alertIds = $this->alerts === [] ? [(string) $this->payload['id']] : array_column($this->alerts, 'id');

        foreach ($alertIds as $alertId) {
            app(RecordAlertDeliveryAction::class)->execute($alertId, $this->channel, $isSuccess);
        }
    }
}
