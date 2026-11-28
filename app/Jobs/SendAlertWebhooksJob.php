<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;

/**
 * Sends the due alert webhook deliveries right after an alert is stored, so the first attempt
 * does not wait for the per-minute `outbound:work-deliveries` run. The package leases each
 * delivery before its send, so this job and the scheduled run never send one twice.
 */
class SendAlertWebhooksJob implements ShouldQueue
{
    use Queueable;

    /** Deliveries one run takes, as the package command's default. */
    public const LIMIT = 50;

    /** Above the package's 15 s webhook timeout and its 30 s chain deadline. */
    public int $timeout = 60;

    /**
     * @side-effect Outbound HTTP POSTs through the package's pinned client; attempt rows.
     */
    public function handle(WebhookDeliveryCoordinator $coordinator): void
    {
        $coordinator->work(self::LIMIT);
    }
}
