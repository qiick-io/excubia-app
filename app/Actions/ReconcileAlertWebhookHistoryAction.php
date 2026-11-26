<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Marks the alert history's webhook `failed` for deliveries qiick/laravel-outbound will never send
 * and never announces: a pending delivery of an endpoint deleted when its URL was cleared, and a
 * delivery the prune exhausted because its body expired (the package fires no failed event for
 * either). Without this, those alerts would read `queued` forever.
 *
 * Runs when the URL is cleared and after the daily prune. Only rows still `queued` change, so a
 * delivery already recorded `sent` or `failed` keeps its outcome.
 */
class ReconcileAlertWebhookHistoryAction
{
    public function __construct(private readonly RecordAlertDeliveryAction $recordDelivery) {}

    /**
     * @return int how many alerts were marked failed
     */
    public function execute(): int
    {
        $abandonedAlertIds = WebhookDelivery::query()
            ->where(fn ($abandoned) => $abandoned
                ->whereIn('status', [DeliveryStatus::Exhausted->value, DeliveryStatus::Cancelled->value])
                ->orWhere(fn ($orphaned) => $orphaned
                    ->where('status', DeliveryStatus::Pending->value)
                    ->whereIn('endpoint_id', WebhookEndpoint::onlyTrashed()->select('id'))))
            ->whereIn('event_id', AlertLog::query()->where('deliveries->'.DeliverAlertJob::WEBHOOK, AlertLog::QUEUED)->select('uuid'))
            ->distinct()
            ->pluck('event_id');

        foreach ($abandonedAlertIds as $alertId) {
            $this->recordDelivery->execute($alertId, DeliverAlertJob::WEBHOOK, false);
        }

        return $abandonedAlertIds->count();
    }
}
