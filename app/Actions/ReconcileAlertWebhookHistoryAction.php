<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\DeliverAlertJob;
use App\Models\AlertLog;
use Illuminate\Support\Str;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Marks the alert history's webhook `failed` for deliveries qiick/laravel-outbound will never send
 * and never announces: a pending delivery of an endpoint deleted when its URL was cleared, and a
 * delivery the prune exhausted because its body expired (the package fires no failed event for
 * either). Without this, those alerts would read `queued` forever.
 *
 * It also catches the deliveries that no longer exist: the prune can exhaust and delete a row in
 * one run (and an operator may run it by hand), but an alert's delivery is stored in the alert's
 * own transaction, so a `queued` alert without one is abandoned for good.
 *
 * Runs when the URL is cleared and around the daily prune. Only rows still `queued` change, so a
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
        $marked = 0;

        // Two queries, not a subquery: `event_id` is a string and `alert_logs.uuid` a uuid, which
        // PostgreSQL refuses to compare in SQL; bound values cast fine.
        WebhookDelivery::query()
            ->where(fn ($abandoned) => $abandoned
                ->whereIn('status', [DeliveryStatus::Exhausted->value, DeliveryStatus::Cancelled->value])
                ->orWhere(fn ($orphaned) => $orphaned
                    ->where('status', DeliveryStatus::Pending->value)
                    ->whereIn('endpoint_id', WebhookEndpoint::onlyTrashed()->select('id')))
                // Pending past its body retention: the next prune exhausts it, and may delete the
                // row in the same run, so it is reconciled before the prune.
                ->orWhere(fn ($expiring) => $expiring
                    ->where('status', DeliveryStatus::Pending->value)
                    ->where('payload_expires_at', '<=', now())))
            ->select(['id', 'event_id'])
            ->chunkById(500, function ($deliveries) use (&$marked): void {
                $queuedAlertIds = AlertLog::query()
                    ->whereIn('uuid', $deliveries->pluck('event_id')->unique()->filter(fn (mixed $eventId): bool => Str::isUuid($eventId))->values())
                    ->where('deliveries->'.DeliverAlertJob::WEBHOOK, AlertLog::QUEUED)
                    ->pluck('uuid');

                foreach ($queuedAlertIds as $alertId) {
                    $this->recordDelivery->execute($alertId, DeliverAlertJob::WEBHOOK, false);
                    $marked++;
                }
            });

        // Queued alerts whose delivery row is gone. Compared in PHP, for the same uuid/string reason.
        AlertLog::query()
            ->where('deliveries->'.DeliverAlertJob::WEBHOOK, AlertLog::QUEUED)
            ->select(['id', 'uuid'])
            ->chunkById(500, function ($alerts) use (&$marked): void {
                $alertIds = $alerts->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid);
                $alertIdsWithDelivery = WebhookDelivery::query()->whereIn('event_id', $alertIds)->pluck('event_id')->all();

                foreach ($alertIds->diff($alertIdsWithDelivery) as $alertId) {
                    $this->recordDelivery->execute($alertId, DeliverAlertJob::WEBHOOK, false);
                    $marked++;
                }
            });

        return $marked;
    }
}
