<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\StoreRuntimeRollupsAction;
use App\Models\RuntimeAggregate;
use App\Models\RuntimeHourlyRollup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the hourly rollups of the dashboard totals (#137) from the stored runtime aggregates,
 * once, for the minutes pushed before the rollups existed. Ingest keeps them current afterwards.
 *
 * ponytail: one transaction reads every aggregate in chunks (about a minute at 1.8 million rows);
 * run it right after the upgrade, not on a schedule.
 */
class BackfillRuntimeRollupsCommand extends Command
{
    protected $signature = 'excubia:backfill-runtime-rollups';

    protected $description = 'Rebuild the hourly runtime rollups of the dashboard from the stored aggregates';

    public function handle(StoreRuntimeRollupsAction $storeRollups): int
    {
        $hours = DB::transaction(function () use ($storeRollups): int {
            RuntimeHourlyRollup::query()->delete();

            RuntimeAggregate::query()->toBase()
                ->select(['id', 'project_id', 'type', 'bucket', 'sample_rate', 'count', 'error_count', 'total_ms'])
                ->chunkById(5000, function ($rows) use ($storeRollups): void {
                    $storeRollups->execute($rows->map(fn (object $row): array => [
                        'project_id' => (int) $row->project_id,
                        'type' => (string) $row->type,
                        'bucket' => (string) $row->bucket,
                        'sample_rate' => (float) $row->sample_rate,
                        'count' => (int) $row->count,
                        'error_count' => (int) $row->error_count,
                        'total_ms' => $row->total_ms === null ? null : (float) $row->total_ms,
                    ])->all());
                });

            return RuntimeHourlyRollup::query()->count();
        });

        $this->components->info("Stored {$hours} hourly runtime rollups.");

        return self::SUCCESS;
    }
}
