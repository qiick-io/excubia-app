<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyScopeEnum;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\LogOptions;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A personal API key (#315, #351): acts as its owner, scopes only narrow what it may do. The plain
 * token is shown once; only SHA-256 of the full token is stored. `key_prefix` is the public part
 * (`exc_{env}_{id}`), generated independently of the secret, for lookup and display.
 *
 * Token shape: `exc_(live|test)_<8-char-id><≥32-char-secret>` (fleet: ext|exc|ins|sig).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property int $owner_id
 * @property int $created_by
 * @property list<string> $scopes
 * @property list<string>|null $ip_allowlist
 * @property int|null $rate_limit_per_minute
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $revoked_at
 * @property string|null $previous_key_hash
 * @property Carbon|null $previous_key_valid_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read User $creator
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUuids, LogsApplicationActivityTrait;

    /** Three-letter product prefix in the fleet token format. */
    public const PRODUCT_PREFIX = 'exc';

    public const PUBLIC_ID_LENGTH = 8;

    public const SECRET_LENGTH = 32;

    /**
     * Full presented token: public part + secret. Group 1 is stored in `key_prefix`.
     */
    /** `D` = PCRE_DOLLAR_ENDONLY: `$` is the real end, not before a trailing newline. */
    public const TOKEN_PATTERN = '/^(exc_(?:live|test)_[A-Za-z0-9]{8})([A-Za-z0-9]{32,})$/D';

    /** Fleet scanner regex covering every product's issued tokens. */
    public const FLEET_SCANNER_PATTERN = '/^(ext|exc|ins|sig)_(live|test)_[A-Za-z0-9]{40,}$/D';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'scopes',
        'ip_allowlist',
        'rate_limit_per_minute',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['key_hash', 'previous_key_hash'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'rate_limit_per_minute' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_key_valid_until' => 'datetime',
        ];
    }

    /**
     * Creates go through actions with an explicit `api-keys` activity entry; hashes never log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->useLogName('api-keys')
            ->logExcept(['key_hash', 'previous_key_hash', 'last_used_at', 'last_used_ip', 'updated_at']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether this key carries the scope, with `write` implying `read` on the same resource.
     * An empty or unrecognised stored list never grants access (fail closed).
     */
    public function hasScope(ApiKeyScopeEnum $scope): bool
    {
        $scopes = $this->scopes;

        if ($scopes === []) {
            return false;
        }

        if (in_array($scope->value, $scopes, true)) {
            return true;
        }

        if (str_ends_with($scope->value, ':read')) {
            $writeScope = substr($scope->value, 0, -strlen(':read')).':write';

            return in_array($writeScope, $scopes, true);
        }

        return false;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Null or empty allowlist permits every address; otherwise the client IP must match an entry
     * or CIDR (Symfony IpUtils).
     */
    public function allowsIp(string $ipAddress): bool
    {
        $allowlist = $this->ip_allowlist;

        if ($allowlist === null || $allowlist === []) {
            return true;
        }

        return IpUtils::checkIp($ipAddress, $allowlist);
    }

    /**
     * Whether `$presentedToken` matches the current hash, or the previous hash inside the grace
     * window. Comparison is always `hash_equals` on SHA-256 of the full presented string.
     */
    public function matchesPresentedToken(string $presentedToken): bool
    {
        $presentedHash = self::hashToken($presentedToken);

        if (hash_equals($this->key_hash, $presentedHash)) {
            return true;
        }

        if (
            $this->previous_key_hash === null
            || $this->previous_key_valid_until === null
            || $this->previous_key_valid_until->isPast()
        ) {
            return false;
        }

        return hash_equals($this->previous_key_hash, $presentedHash);
    }

    /**
     * Issue a new token: public part `exc_{env}_{id}`, secret ≥32 base62 chars. The hash covers
     * the full string the client receives.
     *
     * @return array{plain: string, prefix: string, hash: string}
     */
    public static function generateToken(?string $existingPublicPrefix = null): array
    {
        $prefix = $existingPublicPrefix ?? self::newPublicPrefix();
        $plain = $prefix.Str::random(self::SECRET_LENGTH);

        return [
            'plain' => $plain,
            'prefix' => $prefix,
            'hash' => self::hashToken($plain),
        ];
    }

    /**
     * Public lookup part only: `exc_{live|test}_{8-char-id}`.
     */
    public static function newPublicPrefix(): string
    {
        $environment = app()->isProduction() ? 'live' : 'test';

        return self::PRODUCT_PREFIX.'_'.$environment.'_'.Str::random(self::PUBLIC_ID_LENGTH);
    }

    /**
     * Extract the public `key_prefix` from a presented token, or null when the shape is wrong.
     */
    public static function publicPrefixFromToken(string $presentedToken): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $presentedToken, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * SHA-256 of the full token (prefix, env, id and secret together), never bcrypt/argon2.
     */
    public static function hashToken(string $fullToken): string
    {
        return hash('sha256', $fullToken);
    }
}
