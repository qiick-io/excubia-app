<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectVisibilityEnum;
use App\Services\EffectivePermissionResolverService;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Qiick\Audit\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A Laravel project watched by the dashboard. It pushes its reports with `excubia push`
 * (qiick/excubia-core), authenticated by a push token stored only as a SHA-256 hash. The system
 * project is Excubia itself (#70): seeded, reported locally, never deleted.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_enabled
 * @property bool $is_system
 * @property ProjectVisibilityEnum $visibility
 * @property string|null $push_token_hash
 * @property Carbon|null $last_report_at
 * @property string|null $environment Laravel `app.env` last reported by a report or runtime batch (core #203)
 * @property Carbon|null $stale_alerted_at
 * @property int $skipped_runtime_aggregates runtime entries of unknown types skipped on ingest (#111)
 * @property string|null $logo_path uploaded logo on the files disk (#287, #312); null shows the initials avatar
 * @property float|null $ai_monthly_budget estimated AI spend per month (USD) that alerts at 80 % and 100 % (#129)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'is_enabled', 'is_system', 'visibility'])]
#[Hidden(['push_token_hash'])]
class Project extends Model
{
    /** The pattern of a reported `environment` (core #203): a short name, never a hostname. */
    public const ENVIRONMENT_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @use HasFactory<ProjectFactory> */
    use HasFactory, LogsActivity;

    /**
     * Column defaults, so a new model has them before it is refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => false,
        'visibility' => 'everyone',
    ];

    /**
     * Token changes are logged as `token_rotated` / `token_revoked` events, never the hash.
     * `last_report_at` and `environment` change on pushes and are not admin changes.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->auditActivityLogOptions()->logExcept(['push_token_hash', 'last_report_at', 'environment', 'stale_alerted_at', 'skipped_runtime_aggregates']);
    }

    /**
     * The project groups this project belongs to (#252).
     *
     * @return BelongsToMany<ProjectGroup, $this>
     */
    public function projectGroups(): BelongsToMany
    {
        return $this->belongsToMany(ProjectGroup::class, 'project_group_project')->withTimestamps();
    }

    /**
     * @return HasMany<HealthReport, $this>
     */
    public function healthReports(): HasMany
    {
        return $this->hasMany(HealthReport::class);
    }

    /**
     * @return HasMany<RuntimeAggregate, $this>
     */
    public function runtimeAggregates(): HasMany
    {
        return $this->hasMany(RuntimeAggregate::class);
    }

    /**
     * @return HasMany<ExceptionGroup, $this>
     */
    public function exceptionGroups(): HasMany
    {
        return $this->hasMany(ExceptionGroup::class);
    }

    /**
     * Findings across reports (#18).
     *
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * AI usage per day, provider and model (#129).
     *
     * @return HasMany<AiUsageDay, $this>
     */
    public function aiUsageDays(): HasMany
    {
        return $this->hasMany(AiUsageDay::class);
    }

    /**
     * Schedule inventory (#110), in schedule order.
     *
     * @return HasMany<ScheduledTask, $this>
     */
    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('position');
    }

    /**
     * @return HasOne<HealthReport, $this>
     */
    public function latestReport(): HasOne
    {
        return $this->hasOne(HealthReport::class)->latestOfMany('received_at');
    }

    /**
     * Projects the user may see: all of them for admins and super-admins, otherwise those visible
     * to everyone plus those in a project group linked to one of the user's groups (#253). The
     * single access filter: lists, policies, alert recipients and AI tools all go through it.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, ?User $user): void
    {
        if ($user !== null && app(EffectivePermissionResolverService::class)->isAdmin($user)) {
            return;
        }

        if ($user === null) {
            $query->where('visibility', ProjectVisibilityEnum::Everyone->value);

            return;
        }

        $query->where(fn (Builder $visible) => $visible
            ->where('visibility', ProjectVisibilityEnum::Everyone->value)
            ->orWhereIn('id', self::scopedProjectIds($user)));
    }

    /**
     * Route model binding only finds projects the signed-in user may see, so a hidden project
     * answers 404 like a slug that does not exist: no 403 tells a reader that it exists (#423).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = auth()->user();

        return $this->resolveRouteBindingQuery(self::query()->visibleTo($user instanceof User ? $user : null), $value, $field)->first();
    }

    /**
     * Whether the user may see this project: one query with the same rules as visibleTo(), for
     * the policies and the alert recipients.
     */
    public function isVisibleTo(?User $user): bool
    {
        return self::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /**
     * The ids of the projects in a project group linked to one of the user's live groups (#253),
     * through an unexpired membership, as a subquery: no extra round trip for visibleTo().
     */
    private static function scopedProjectIds(User $user): QueryBuilder
    {
        return DB::table('project_group_project')
            ->select('project_group_project.project_id')
            ->join('user_group_project_group', 'user_group_project_group.project_group_id', '=', 'project_group_project.project_group_id')
            ->join('user_group_user', 'user_group_user.user_group_id', '=', 'user_group_project_group.user_group_id')
            // A deleted group (soft-deleted, its pivots kept) and an expired membership grant
            // nothing, as for the group's roles.
            ->join('user_groups', 'user_groups.id', '=', 'user_group_user.user_group_id')
            ->whereNull('user_groups.deleted_at')
            ->where(fn (QueryBuilder $membership) => $membership
                ->whereNull('user_group_user.expires_at')
                ->orWhere('user_group_user.expires_at', '>', now()))
            ->where('user_group_user.user_id', $user->getKey());
    }

    /**
     * The relative URL of the uploaded logo (#287), or null when the project has no logo.
     * The URL changes with each upload so the browser requests the replacement image.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return route('projects.logo', ['project' => $this->slug, 'v' => pathinfo($this->logo_path, PATHINFO_FILENAME)], false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_system' => 'boolean',
            'visibility' => ProjectVisibilityEnum::class,
            'last_report_at' => 'datetime',
            'stale_alerted_at' => 'datetime',
            'ai_monthly_budget' => 'float',
        ];
    }
}
