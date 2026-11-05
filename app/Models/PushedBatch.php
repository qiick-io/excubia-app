<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A runtime or exceptions batch already stored, so a retried push with the same `batchId` is
 * not counted twice.
 *
 * @property int $id
 * @property int $project_id
 * @property string $type
 * @property string $batch_id
 * @property Carbon $received_at
 */
#[Fillable(['project_id', 'type', 'batch_id', 'received_at'])]
class PushedBatch extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
