<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One user erasure case (#381), driven by ErasureOrchestratorService.
 *
 * @property string $id
 * @property string $mode
 * @property string|null $organisation_id
 * @property string $status
 * @property array<string, array{status: string, changed?: int}> $steps
 * @property array{userId: string, userPublicId: string, publicIds: list<string>, identifiers: list<string>, apiKeyIdsByPublicId: array<string, int>, email?: string}|null $subject
 * @property int|null $user_id
 * @property string|null $claim_token
 * @property Carbon|null $claimed_until
 * @property int $attempts
 * @property string|null $last_error_code
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 */
class ErasureRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** A snapshot that no longer decrypts (a rotated APP_KEY): an admin must act, no retry. */
    public const ERROR_SNAPSHOT_UNREADABLE = 'snapshot_unreadable';

    protected $fillable = [
        'mode',
        'organisation_id',
        'status',
        'steps',
        'subject',
        'user_id',
        'claim_token',
        'claimed_until',
        'attempts',
        'last_error_code',
        'requested_at',
        'completed_at',
    ];

    /**
     * The decrypted snapshot; null once the case is closed.
     *
     * @return array{userId: string, userPublicId: string, publicIds: list<string>, identifiers: list<string>, apiKeyIdsByPublicId: array<string, int>, email?: string}|null
     *
     * @throws DecryptException when the snapshot no longer decrypts (a rotated APP_KEY)
     */
    public function readSubject(): ?array
    {
        return $this->subject;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'subject' => 'encrypted:array',
            'attempts' => 'integer',
            'claimed_until' => 'datetime',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
