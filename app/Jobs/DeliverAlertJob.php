<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RecordAlertDeliveryAction;
use App\Mail\AlertMail;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Delivers one alert (#22) to one channel, as externa-core's outbound webhook and notifier jobs:
 * - webhook: JSON body signed with HMAC-SHA256 of `timestamp.body` (`X-Excubia-Signature`);
 * - slack: an incoming-webhook message;
 * - telegram: a Bot API sendMessage;
 * - email:<user id>: an AlertMail to that user, in their locale (the user must still be active).
 * HTTPS only, no redirects, and hosts that resolve to private or reserved addresses are refused;
 * the connection goes to the checked address, and transport errors never quote the URL.
 * Retried 3 times with backoff; the webhook's last success and last error are kept for Settings,
 * and every channel's outcome goes on the alert's history row (#114). A grouped message (one
 * report push, #22) carries the worst alert's payload and lists every alert in `alerts`, whose
 * history rows all get the outcome.
 */
class DeliverAlertJob implements ShouldQueue
{
    use Queueable;

    public const WEBHOOK = 'webhook';

    public const SLACK = 'slack';

    public const TELEGRAM = 'telegram';

    /** Prefix of the per-user email channel, `email:<user id>`. */
    public const EMAIL = 'email';

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
    public function handle(SettingsRepositoryService $settings): void
    {
        $userId = self::emailUserId($this->channel);

        if ($userId !== null) {
            $this->sendMail($settings, $userId);

            return;
        }

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

        $address = $url === null ? null : $this->publicAddress($url);

        if ($url === null || $address === null) {
            $this->record($settings, false, 'Refused: the URL is missing, not HTTPS or points to a private address.');

            return;
        }

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withoutRedirecting()
                // Connects to the address checked above, so a second DNS answer cannot point the
                // request at a private host (DNS rebinding, #51).
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->pinnedResolve($url, $address)]]])
                ->withHeaders(['User-Agent' => 'Excubia-Alerts/1.0', ...$headers])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (Throwable $exception) {
            // Transport errors quote the URL, which holds the Slack webhook secret or the
            // Telegram bot token: never let it reach failed_jobs, the logs or Settings (#51).
            throw new RuntimeException("Alert delivery to {$this->channel} failed: ".class_basename($exception));
        }

        if (! $response->successful()) {
            throw new RuntimeException("Alert delivery to {$this->channel} failed: HTTP {$response->status()}");
        }

        $this->record($settings, true, "HTTP {$response->status()}");
    }

    /**
     * The user id of an `email:<user id>` channel, else null.
     */
    public static function emailUserId(string $channel): ?int
    {
        return preg_match('/^'.self::EMAIL.':(\d+)$/', $channel, $matches) === 1 ? (int) $matches[1] : null;
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
     * The address to connect to: the URL is HTTPS and every IPv4 and IPv6 address of its host is
     * public (no loopback, private, link-local, mapped or reserved range). Null when refused, also
     * when the host does not resolve, so an unresolvable or IPv6-only name is never let through
     * unchecked (#51).
     */
    private function publicAddress(string $url): ?string
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [
            ...(gethostbynamel($host) ?: []),
            ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        ];
        $isPublic = fn (string $address): bool => ! IpUtils::isPrivateIp($address)
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        return $addresses !== [] && collect($addresses)->every($isPublic) ? $addresses[0] : null;
    }

    /**
     * The `CURLOPT_RESOLVE` entry that maps the URL's host and port to the checked address.
     */
    private function pinnedResolve(string $url, string $address): string
    {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        return sprintf('%s:%d:%s', $host, (int) ($parts['port'] ?? 443), str_contains($address, ':') ? "[{$address}]" : $address);
    }

    /**
     * Keeps the outcome on the alert's history row, and the webhook's last success and last
     * error for the delivery status cards.
     */
    private function record(SettingsRepositoryService $settings, bool $isSuccess, string $message): void
    {
        $alertIds = $this->alerts === [] ? [(string) $this->payload['id']] : array_column($this->alerts, 'id');

        foreach ($alertIds as $alertId) {
            app(RecordAlertDeliveryAction::class)->execute($alertId, $this->channel, $isSuccess);
        }

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
