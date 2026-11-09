<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Named group of users that carries roles. Every member inherits the group's roles, and also
 * sees the projects of the project groups linked to the group (#253), admin-only ones included.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class UserGroup extends Model
{
    use LogsApplicationActivityTrait;

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
