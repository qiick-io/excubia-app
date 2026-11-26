<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Audit\Concerns\LogsActivity;
use Spatie\Permission\Models\Role;

/**
 * Named group of users that carries roles. Every member inherits the group's roles, and also
 * sees the projects of the project groups linked to the group (#253), admin-only ones included.
 * Soft-deleted (fleet shape): the effective-permission resolver must ignore deleted groups.
 *
 * The table is qiick/laravel-access' (`user_groups`, `user_group_user`, `user_group_role`); the
 * product generates `public_id` (UUID v7). Excubia has no organisations, so every group is an
 * instance group (`is_instance`): the only kind whose super-admin role makes its members
 * super-admins (C30), as every Excubia group did before the package.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'description'])]
class UserGroup extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['is_instance' => true];

    /**
     * Deleting or restoring a group changes its members' roles without a Spatie event: drop the
     * resolver's per-request cache so the very next check sees it.
     */
    protected static function booted(): void
    {
        static::deleted(fn () => app(EffectivePermissionResolver::class)->flush());
        static::restored(fn () => app(EffectivePermissionResolver::class)->flush());
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_group_role')->withTimestamps();
    }

    /**
     * Project groups whose projects the members see (#253). The pivot has its own id for a
     * later per-scope role.
     *
     * @return BelongsToMany<ProjectGroup, $this>
     */
    public function projectGroups(): BelongsToMany
    {
        return $this->belongsToMany(ProjectGroup::class, 'user_group_project_group')->withTimestamps();
    }
}
