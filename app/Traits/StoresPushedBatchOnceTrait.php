<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Project;
use App\Models\PushedBatch;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Stores a runtime or exceptions batch once per `batchId` (#48), safe under concurrent pushes
 * (#195). The batch id and the batch's rows go in one transaction, which runs again when the
 * database reports a deadlock (MySQL's gap and upsert locks between concurrent pushes) or, past
 * the busy timeout, a locked SQLite file. A unique violation means a duplicate only when the
 * batch id is already stored; otherwise a concurrent batch of the same project created one of
 * the same series first, and the transaction runs again to add to it.
 */
trait StoresPushedBatchOnceTrait
{
    /** Tries of the store transaction, for deadlocks and for series created concurrently. */
    private const STORE_ATTEMPTS = 5;

    /**
     * @param  'runtime'|'exceptions'  $type
     * @param  Closure(): void  $store  writes the batch's rows, inside the transaction
     * @return bool true when the batch was new
     */
    private function storeOnce(Project $project, string $type, string $batchId, Closure $store): bool
    {
        $batch = ['project_id' => $project->id, 'type' => $type, 'batch_id' => $batchId];

        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($batch, $store): void {
                    PushedBatch::query()->create([...$batch, 'received_at' => Date::now()]);
                    $store();
                }, self::STORE_ATTEMPTS);

                return true;
            } catch (UniqueConstraintViolationException $exception) {
                if (PushedBatch::query()->where($batch)->exists()) {
                    return false;
                }

                if ($attempt >= self::STORE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
