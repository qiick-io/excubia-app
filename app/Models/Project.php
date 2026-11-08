<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectVisibilityEnum;
use App\Services\EffectivePermissionResolverService;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $stale_alerted_at
 * @property int $skipped_runtime_aggregates runtime entries of unknown types skipped on ingest (#111)
 * @property float|null $ai_monthly_budget estimated AI spend per month (USD) that alerts at 80 % and 100 % (#129)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'is_enabled', 'is_system', 'visibility'])]
#[Hidden(['push_token_hash'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, LogsApplicationActivityTrait;

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
     * `last_report_at` changes on every push and is not an admin change.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()->logExcept(['push_token_hash', 'last_report_at', 'stale_alerted_at', 'skipped_runtime_aggregates']);
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
     * Projects the user may see: all of them for admins and super-admins, otherwise only those
     * visible to everyone.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, ?User $user): void
    {
        if ($user !== null && app(EffectivePermissionResolverService::class)->isAdmin($user)) {
            return;
        }

        $query->where('visibility', ProjectVisibilityEnum::Everyone->value);
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
