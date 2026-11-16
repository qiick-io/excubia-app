<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyScopeEnum;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A personal API key (#315), as externa-core's `ApiKey` but owned by one user: it acts as that
 * user, so it can never see or do more than they can in the UI. Scopes only narrow what the key
 * may be used for. The plain key is shown once; only its SHA-256 hash is stored (a fast hash is
 * enough for 40 random characters and lets a request find its key by hash).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property list<string> $scopes
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, LogsApplicationActivityTrait;

    /** Starts every key, so a leaked one is recognisable (push tokens start with `excubia_`). */
    public const KEY_PREFIX = 'exk_';

    /** @var list<string> */
    protected $fillable = ['name', 'scopes', 'expires_at'];

    /** @var list<string> */
    protected $hidden = ['key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Created keys go to the `auth` log without their hash; revocation and first use are logged
     * by their actions.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->useLogName('auth')
            ->logExcept(['key_hash', 'last_used_at', 'updated_at']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasScope(ApiKeyScopeEnum $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * The hash a plain key is stored and looked up by.
     */
    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }
}
