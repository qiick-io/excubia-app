<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyScopeEnum;
use App\Enums\PrincipalTypeEnum;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\ApiKeyFactory;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\CredentialKind;
use Qiick\ApiKeys\RevocationReason;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A personal API key (#315, #351): acts as its owner, scopes only narrow what it may do. The plain
 * token is shown once; only SHA-256 of the full token is stored. `key_prefix` is the public part
 * (`exc_{env}_{id}`), for lookup and display.
 *
 * Token issue, verification, rotation and the refusal format belong to qiick/laravel-api-keys:
 * this model is the product's storage behind its {@see ApiKeyRecord} contract.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $key_prefix
 * @property string $key_hash
 * @property int $token_version
 * @property string $hash_algorithm
 * @property string|null $previous_key_prefix
 * @property string|null $previous_key_hash
 * @property Carbon|null $previous_key_valid_until
 * @property string $credential_kind
 * @property string|null $oauth_client_id
 * @property int $owner_id
 * @property string $owner_principal_id
 * @property int|null $created_by null once the issuing user is removed
 * @property list<string> $scopes
 * @property list<string>|null $ip_allowlist
 * @property array<string, mixed>|null $restrictions
 * @property int|null $rate_limit_per_minute
 * @property int|null $rate_limit_per_day
 * @property int|null $rate_limit_per_month
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $expiry_notified_at
 * @property Carbon|null $expiry_notice_claimed_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property string|null $last_used_user_agent
 * @property Carbon|null $last_rotated_at
 * @property Carbon|null $exposed_at
 * @property string|null $exposure_source
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoked_reason
 * @property string|null $revocation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $owner null once the owning user is soft-deleted
 * @property-read Principal $ownerPrincipal
 * @property-read User|null $creator null once the issuing user is removed
 */
class ApiKey extends Model implements ApiKeyRecord
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUuids, LogsApplicationActivityTrait;

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
            'restrictions' => 'array',
            'metadata' => 'array',
            'token_version' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'rate_limit_per_day' => 'integer',
            'rate_limit_per_month' => 'integer',
            'previous_key_valid_until' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
            'expiry_notice_claimed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'exposed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Creates go through actions with an explicit `api-keys` activity entry; hashes never log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->useLogName('api-keys')
            ->logExcept([
                'key_hash',
                'previous_key_hash',
                'last_used_at',
                'last_used_ip',
                'last_used_user_agent',
                'expiry_notice_claimed_at',
                'updated_at',
            ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Fleet principal that owns the key; the key works only while it is active.
     *
     * @return BelongsTo<Principal, $this>
     */
    public function ownerPrincipal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'owner_principal_id');
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

    public function id(): int
    {
        return $this->id;
    }

    public function publicId(): string
    {
        return $this->uuid;
    }

    public function keyPrefix(): string
    {
        return $this->key_prefix;
    }

    public function keyHash(): string
    {
        return $this->key_hash;
    }

    public function tokenVersion(): int
    {
        return $this->token_version;
    }

    public function hashAlgorithm(): string
    {
        return $this->hash_algorithm;
    }

    public function previousKeyPrefix(): ?string
    {
        return $this->previous_key_prefix;
    }

    public function previousKeyHash(): ?string
    {
        return $this->previous_key_hash;
    }

    public function previousKeyValidUntil(): ?DateTimeImmutable
    {
        return self::immutable($this->previous_key_valid_until);
    }

    /**
     * An unknown stored kind reads as an OAuth grant, which the authenticator refuses (fail closed).
     */
    public function credentialKind(): CredentialKind
    {
        return CredentialKind::tryFrom($this->credential_kind) ?? CredentialKind::OauthGrant;
    }

    public function oauthClientId(): ?string
    {
        return $this->oauth_client_id;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata ?? [];
    }

    /**
     * The JSON columns go to the package as stored: the `array` cast would turn JSON that does
     * not decode into null, which reads as "no restriction" (the package denies it instead).
     */
    public function rawScopes(): mixed
    {
        return $this->getRawOriginal('scopes');
    }

    public function rawIpAllowlist(): mixed
    {
        return $this->getRawOriginal('ip_allowlist');
    }

    public function rawRestrictions(): mixed
    {
        return $this->getRawOriginal('restrictions');
    }

    public function rateLimitPerMinute(): ?int
    {
        return $this->rate_limit_per_minute;
    }

    public function rateLimitPerDay(): ?int
    {
        return $this->rate_limit_per_day;
    }

    public function rateLimitPerMonth(): ?int
    {
        return $this->rate_limit_per_month;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return self::immutable($this->expires_at);
    }

    public function expiryNotifiedAt(): ?DateTimeImmutable
    {
        return self::immutable($this->expiry_notified_at);
    }

    public function expiryNoticeClaimedAt(): ?DateTimeImmutable
    {
        return self::immutable($this->expiry_notice_claimed_at);
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return self::immutable($this->revoked_at);
    }

    public function revokedByIdentifier(): ?int
    {
        return $this->revoked_by;
    }

    public function revokedReason(): ?RevocationReason
    {
        return $this->revoked_reason === null ? null : RevocationReason::tryFrom($this->revoked_reason);
    }

    public function revocationNote(): ?string
    {
        return $this->revocation_note;
    }

    public function exposedAt(): ?DateTimeImmutable
    {
        return self::immutable($this->exposed_at);
    }

    public function exposureSource(): ?string
    {
        return $this->exposure_source;
    }

    public function lastRotatedAt(): ?DateTimeImmutable
    {
        return self::immutable($this->last_rotated_at);
    }

    public function ownerIdentifier(): int
    {
        return $this->owner_id;
    }

    public function ownerPrincipalIdentifier(): string
    {
        return $this->owner_principal_id;
    }

    public function ownerKind(): ?string
    {
        return $this->ownerPrincipal->type instanceof PrincipalTypeEnum
            ? $this->ownerPrincipal->type->value
            : $this->ownerPrincipal->type;
    }

    public function createdByIdentifier(): ?int
    {
        return $this->created_by;
    }

    /**
     * The row as arrays see it: the hashes stay hidden.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributesToArray();
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return self::immutable($this->last_used_at);
    }

    public function lastUsedIp(): ?string
    {
        return $this->last_used_ip;
    }

    public function lastUsedUserAgent(): ?string
    {
        return $this->last_used_user_agent;
    }

    private static function immutable(?DateTimeInterface $instant): ?DateTimeImmutable
    {
        return $instant === null ? null : DateTimeImmutable::createFromInterface($instant);
    }
}
