<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Deletes old rows in bounded ID batches so retention releases locks between statements. */
class PruneRowsAction
{
    public const BATCH_SIZE = 5000;

    /** Reapply the retention predicate in case ingest refreshed a row after the ID read. */
    public function execute(Builder $query): int
    {
        $deleted = 0;
        $query->select('id')->chunkById(self::BATCH_SIZE, function (Collection $rows) use ($query, &$deleted): void {
            $deleted += (clone $query)->whereIntegerInRaw('id', $rows->pluck('id')->all())->delete();
        });

        return $deleted;
    }
}
