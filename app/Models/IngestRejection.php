<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pushes of one hour rejected by one endpoint with one status and reason (#117), counted by
 * RecordIngestRejectionAction for the System page. Holds no body, token or project.
 *
 * @property int $id
 * @property string $endpoint
 * @property int $status
 * @property string $reason
 * @property Carbon $bucket
 * @property int $count
 */
#[Fillable(['endpoint', 'status', 'reason', 'bucket', 'count'])]
class IngestRejection extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'bucket' => 'datetime',
            'count' => 'integer',
        ];
    }
}
