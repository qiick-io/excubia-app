<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The occurrences of one exception group in one UTC minute (#113), for its chart over a time
 * range. A pushed group carries a count and its first and last occurrence, not every moment:
 * the batch's count lands in the minute of its last occurrence.
 *
 * @property int $id
 * @property int $project_id
 * @property int $exception_group_id
 * @property Carbon $bucket
 * @property int $count
 */
#[Fillable(['project_id', 'exception_group_id', 'bucket', 'count'])]
class ExceptionOccurrence extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket' => 'datetime',
            'count' => 'integer',
        ];
    }
}
