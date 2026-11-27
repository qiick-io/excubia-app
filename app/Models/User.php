<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\Oidc\Models\UserIdentity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $public_id fleet public UUID v7; never the primary key
 * @property string $first_name
 * @property string|null $last_name
 * @property-read string $name first and last name
 * @property string $email
 * @property string|null $username optional, unique and stored lowercase (fleet baseline #84)
 * @property-read bool|null $identities_exists from withExists('identities')
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_active
 * @property string $locale
 * @property string $timezone IANA zone for the account (fleet baseline, default UTC)
 * @property bool $has_weekly_digest weekly email digest (#114)
 * @property bool $has_alert_emails alert emails (#22)
 * @property array<string, mixed>|null $alert_email_filters see AlertFilterData; the in-app alerts read them too
 * @property bool $has_alert_in_app in-app alert notifications (#296), on by default
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at soft-delete marker; deleting a user keeps the audit trail
 */
#[Fillable(['first_name', 'last_name', 'username', 'email', 'locale', 'timezone', 'password', 'has_weekly_digest'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, LogsApplicationActivityTrait, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The full name travels with the user to the pages (avatar, menus).
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * Every user gets a fleet `user` principal (API-key owner) on create, at instance level.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            app(PrincipalDirectory::class)->forMember($user, null);
        });
    }

    /**
     * The column defaults a freshly created user does not read back from the
     * database (same values as the users migration): strict models throw on a
     * missing attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'username' => null,
        'locale' => 'en',
        'timezone' => 'UTC',
        'has_weekly_digest' => false,
        'has_alert_emails' => false,
        'has_alert_in_app' => true,
        'alert_email_filters' => null,
    ];

    /**
     * Only `public_id` is a generated UUID; the primary key stays an auto-increment id.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Activity log options excluding sensitive credential fields.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->logExcept(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token']);
    }

    /**
     * Groups the user belongs to; their roles add to the user's own (EffectivePermissionResolverService).
     *
     * @return BelongsToMany<UserGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user')->withTimestamps();
    }

    /**
     * Projects pinned to the sidebar (#273), in pin order. Visibility is not checked here: the
     * sidebar reads them through `Project::visibleTo()`.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function pinnedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'user_project_pins')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Personal API keys (#315, #351) this user owns, revoked and expired ones included. Deleting
     * the user revokes them; their rows and audit trail stay.
     *
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'owner_id');
    }

    /**
     * Fleet principal for this person (one per user).
     *
     * @return HasOne<Principal, $this>
     */
    public function principal(): HasOne
    {
        return $this->hasOne(Principal::class);
    }

    /**
     * SSO identities (issuer + `sub`) linked to this user by qiick/laravel-oidc (#356).
     *
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Roles and permissions live on the `web` guard. API-key auth switches the default guard to
     * `api_key` for the request (#351); Spatie must still resolve against `web` or every can()
     * and assignRole() would look for a second set of roles that does not exist.
     */
    public function getDefaultGuardName(): string
    {
        return 'web';
    }

    /**
     * The one canonical form of a username, shared by the model and the request validators.
     */
    public static function normalizeUsername(?string $value): ?string
    {
        $normalized = mb_strtolower(trim($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Canonicalize usernames on every write, including CLI and seeder writes that bypass the
     * request validators. The unique index then compares lowercase values on every database.
     */
    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = self::normalizeUsername($value);
    }

    /**
     * Full display name from first and last name, as in externa-core.
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'has_weekly_digest' => 'boolean',
            'has_alert_emails' => 'boolean',
            'has_alert_in_app' => 'boolean',
            'alert_email_filters' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Queued (#472), so an existing address does not answer slower than an unknown one.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
