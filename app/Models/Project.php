<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A Laravel project watched by the dashboard. It pushes its reports with `excubia push`
 * (qiick/excubia-core), authenticated by a push token stored only as a SHA-256 hash.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_enabled
 * @property string|null $push_token_hash
 * @property Carbon|null $last_report_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'is_enabled'])]
#[Hidden(['push_token_hash'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, LogsApplicationActivityTrait;

    /**
     * Token changes are logged as `token_rotated` / `token_revoked` events, never the hash.
     * `last_report_at` changes on every push and is not an admin change.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()->logExcept(['push_token_hash', 'last_report_at']);
    }

    /**
     * @return HasMany<HealthReport, $this>
     */
    public function healthReports(): HasMany
    {
        return $this->hasMany(HealthReport::class);
    }

    /**
     * @return HasOne<HealthReport, $this>
     */
    public function latestReport(): HasOne
    {
        return $this->hasOne(HealthReport::class)->latestOfMany('received_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_report_at' => 'datetime',
        ];
    }
}
