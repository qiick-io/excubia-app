<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendAlertWebhooksJob;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Webhooks\WebhookException;
use Qiick\Outbound\Webhooks\WebhookMessage;
use Qiick\Outbound\Webhooks\WebhookSecretResolver;

/**
 * The signed alert webhook (#22) on qiick/laravel-outbound: Excubia has one instance-level
 * endpoint, subscribed to every alert type, with a signing secret the package generates.
 *
 * Sending stores one delivery per alert (the `webhook-id` is the alert's id) and queues a run of
 * the package's worker, so the first attempt goes out at once; retries, `Retry-After`,
 * auto-disable and body retention are the package's, driven by the per-minute schedule.
 */
class AlertWebhookService
{
    public function __construct(
        private readonly WebhookDeliveryCoordinator $coordinator,
        private readonly WebhookSecretResolver $secrets,
    ) {}

    public function endpoint(): ?WebhookEndpoint
    {
        return WebhookEndpoint::query()->oldest('id')->first();
    }

    /**
     * Whether alerts can be sent: an active endpoint the package can sign for (exactly one live
     * primary secret, as its own resolver decides).
     */
    public function isReady(): bool
    {
        $endpoint = $this->endpoint();

        if ($endpoint === null || ! $endpoint->is_active) {
            return false;
        }

        try {
            $this->secrets->signingSetFor($endpoint);
        } catch (WebhookException) {
            return false;
        }

        return true;
    }

    /**
     * Stores the delivery of one alert payload once the surrounding transaction commits, then
     * queues the worker run that sends it.
     *
     * @param  array<string, mixed>  $payload  see AlertDispatcherService
     *
     * @side-effect Writes webhook_deliveries rows; queues SendAlertWebhooksJob.
     */
    public function send(array $payload): void
    {
        $data = $payload;
        unset($data['id'], $data['event']);

        $message = new WebhookMessage(
            (string) $payload['id'],
            (string) $payload['event'],
            DateTimeImmutable::createFromInterface(Date::now()),
            $data,
        );

        DB::afterCommit(function () use ($message): void {
            $this->coordinator->dispatch($message);
            SendAlertWebhooksJob::dispatch();
        });
    }
}
