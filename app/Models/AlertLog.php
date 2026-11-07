<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One alert the dispatcher handled (#114), for the project's alert history: `queued` until
 * every channel answered, then `sent` or `failed` (one channel failed); `suppressed` with the
 * `reason` (muted, maintenance_window, maintenance_mode, duplicate); `skipped` when no channel takes it.
 * `uuid` is the payload id the webhook receives. `deliveries` maps each channel to queued,
 * sent or failed. `fingerprints` are the core finding fingerprints of a finding or health alert
 * (#143).
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $event
 * @property string $severity
 * @property string|null $subject
 * @property string $summary
 * @property string $status
 * @property string|null $reason
 * @property list<string>|null $fingerprints
 * @property array<string, string> $deliveries
 * @property Carbon $created_at
 */
#[Fillable(['uuid', 'project_id', 'event', 'severity', 'subject', 'summary', 'status', 'reason', 'fingerprints', 'deliveries'])]
class AlertLog extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SUPPRESSED = 'suppressed';

    public const SKIPPED = 'skipped';

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fingerprints' => 'array', 'deliveries' => 'array'];
    }
}
