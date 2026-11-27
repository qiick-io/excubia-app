<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed change to the open inbox, retained for the lifetime of its exception group.
 * ponytail: groups with frequent status flips can accumulate events. Fold old changes into
 * daily checkpoints if activity per group grows beyond routine triage.
 */
#[Fillable(['project_id', 'exception_group_id', 'occurred_at', 'delta'])]
class ExceptionOpenChange extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'delta' => 'integer'];
    }
}
