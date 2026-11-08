<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExceptionStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Exceptions of a project with the same fingerprint in one environment (#49): the masked
 * message, frames and previous classes of the first occurrence, the latest context, the
 * occurrence count and when it was first and last seen.
 *
 * @property int $id
 * @property int $project_id
 * @property string $fingerprint
 * @property string $environment
 * @property string $class
 * @property string $message
 * @property list<array{file: string|null, line: int|null, function: string|null, inApp: bool}> $frames
 * @property list<string> $previous
 * @property string $context_kind
 * @property string $context_name
 * @property int $count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property string|null $first_release release of the first batch with the group (#106)
 * @property string|null $last_release release of the batch with the latest occurrence
 * @property string|null $regressed_release release a resolved group came back in
 * @property ExceptionStatusEnum $status
 * @property Carbon|null $resolved_at
 * @property int|null $range_count occurrences in the time range, when loaded (#113)
 */
#[Fillable(['project_id', 'fingerprint', 'environment', 'class', 'message', 'frames', 'previous', 'context_kind', 'context_name', 'count', 'first_seen_at', 'last_seen_at', 'first_release', 'last_release', 'regressed_release', 'status', 'resolved_at'])]
class ExceptionGroup extends Model
{
    /** Context kinds of excubia-core (`docs/exception-schema.json`); any other is stored as `unknown` (#169). */
    public const CONTEXT_KINDS = ['request', 'job', 'command', 'unknown'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Occurrences per minute (#113).
     *
     * @return HasMany<ExceptionOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ExceptionOccurrence::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frames' => 'array',
            'previous' => 'array',
            'count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'status' => ExceptionStatusEnum::class,
            'resolved_at' => 'datetime',
        ];
    }
}
