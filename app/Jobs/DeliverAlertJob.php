<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Delivers one alert (#22) to one channel, as externa-core's outbound webhook and notifier jobs:
 * - webhook: JSON body signed with HMAC-SHA256 of `timestamp.body` (`X-Excubia-Signature`);
 * - slack: an incoming-webhook message;
 * - telegram: a Bot API sendMessage.
 * HTTPS only, no redirects, and hosts that resolve to private or reserved addresses are refused.
 * Retried 3 times with backoff; the webhook's last success and last error are kept for Settings.
 */
class DeliverAlertJob implements ShouldQueue
{
    use Queueable;

    public const WEBHOOK = 'webhook';

    public const SLACK = 'slack';

    public const TELEGRAM = 'telegram';

    public int $tries = 3;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $payload  see AlertDispatcherService
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $payload,
        public readonly string $text = '',
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
    public function handle(SettingsRepositoryService $settings): void
    {
        [$url, $headers, $body] = match ($this->channel) {
            self::WEBHOOK => $this->webhookRequest($settings),
            self::SLACK => [$settings->slackWebhookUrl(), [], (string) json_encode(['text' => $this->text], JSON_THROW_ON_ERROR)],
            self::TELEGRAM => [
                'https://api.telegram.org/bot'.$settings->telegramBotToken().'/sendMessage',
                [],
                (string) json_encode(['chat_id' => $settings->telegramChatId(), 'text' => $this->text, 'disable_web_page_preview' => true], JSON_THROW_ON_ERROR),
            ],
            default => [null, [], ''],
        };

        if ($url === null || ! $this->isSafeUrl($url)) {
            $this->record($settings, false, 'Refused: the URL is missing, not HTTPS or points to a private address.');

            return;
        }

        $response = Http::timeout(5)
            ->connectTimeout(3)
            ->withoutRedirecting()
            ->withHeaders(['User-Agent' => 'Excubia-Alerts/1.0', ...$headers])
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException("Alert delivery to {$this->channel} failed: HTTP {$response->status()}");
        }

        $this->record($settings, true, "HTTP {$response->status()}");
    }

    public function failed(Throwable $exception): void
    {
        $this->record(app(SettingsRepositoryService::class), false, $exception->getMessage());
    }

    /**
     * @return array{0: string|null, 1: array<string, string>, 2: string}
     */
    private function webhookRequest(SettingsRepositoryService $settings): array
    {
        $secret = $settings->webhookSecret();
        $body = (string) json_encode($this->payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) Date::now()->timestamp;

        if ($secret === null) {
            return [null, [], $body];
        }

        return [$settings->webhookUrl(), [
            'X-Excubia-Event' => (string) $this->payload['event'],
            'X-Excubia-Event-Id' => (string) $this->payload['id'],
            'X-Excubia-Timestamp' => $timestamp,
            'X-Excubia-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body];
    }

    /**
     * HTTPS, and every resolved address of the host is public (no loopback, private or
     * reserved range).
     */
    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            return false;
        }

        // A host that does not resolve cannot be reached either; the connection fails on its own.
        $addresses = filter_var($parts['host'], FILTER_VALIDATE_IP) !== false ? [$parts['host']] : (gethostbynamel($parts['host']) ?: []);

        return collect($addresses)->every(
            fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
        );
    }

    /**
     * Keeps the webhook's last success and last error for the delivery status cards.
     */
    private function record(SettingsRepositoryService $settings, bool $isSuccess, string $message): void
    {
        if ($this->channel !== self::WEBHOOK) {
            return;
        }

        $settings->setMany([$isSuccess ? 'webhook_last_success' : 'webhook_last_error' => [
            'event' => $this->payload['event'],
            'eventId' => $this->payload['id'],
            'at' => Date::now()->toIso8601ZuluString(),
            'message' => $isSuccess ? null : mb_substr($message, 0, 300),
        ]]);
    }
}
