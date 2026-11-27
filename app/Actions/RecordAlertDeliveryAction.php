<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AlertLog;
use Illuminate\Support\Facades\DB;

/**
 * Writes one channel's outcome on the alert's history row (#114): the row is `failed` once a
 * channel failed, `sent` when every channel delivered, `queued` meanwhile. Locked, so channels
 * finishing at the same time do not overwrite each other. A test event has no row: no-op.
 */
class RecordAlertDeliveryAction
{
    public function execute(string $alertId, string $channel, bool $isDelivered): void
    {
        DB::transaction(function () use ($alertId, $channel, $isDelivered): void {
            $log = AlertLog::query()->where('uuid', $alertId)->lockForUpdate()->first();

            if ($log === null) {
                return;
            }

            $deliveries = [...$log->deliveries, $channel => $isDelivered ? AlertLog::SENT : AlertLog::FAILED];

            $log->forceFill([
                'deliveries' => $deliveries,
                'status' => match (true) {
                    in_array(AlertLog::FAILED, $deliveries, true) => AlertLog::FAILED,
                    in_array(AlertLog::QUEUED, $deliveries, true) => AlertLog::QUEUED,
                    default => AlertLog::SENT,
                },
            ])->save();
        });
    }
}
