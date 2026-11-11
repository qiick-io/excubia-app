<?php

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $first_name
 * @property string|null $last_name
 * @property-read string $name first and last name
 * @property string $email
 * @property string|null $oidc_sub
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_active
 * @property string $locale
 * @property bool $has_weekly_digest weekly email digest (#114)
 * @property bool $has_alert_emails alert emails (#22)
 * @property array<string, mixed>|null $alert_email_filters see AlertFilterData
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['first_name', 'last_name', 'email', 'password', 'has_weekly_digest'])]
#[Hidden(['password', 'oidc_sub', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsApplicationActivityTrait, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The full name travels with the user to the pages (avatar, menus).
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * The column defaults a freshly created user does not read back from the
     * database (same values as the users migration): strict models throw on a
     * missing attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'locale' => 'en',
        'has_weekly_digest' => false,
        'has_alert_emails' => false,
        'alert_email_filters' => null,
    ];

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
            'alert_email_filters' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
