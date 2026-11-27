<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Named group of projects (#252), generic on purpose: a client, a team, a product line or an
 * environment. It scopes the dashboard and the alert filters; a project may sit in several groups.
 * Linked to user groups, it grants their members access to its projects (#253).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description'])]
class ProjectGroup extends Model
{
    use LogsApplicationActivityTrait;

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_group_project')->withTimestamps();
    }

    /**
     * User groups whose members see this group's projects (#253).
     *
     * @return BelongsToMany<UserGroup, $this>
     */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_project_group')->withTimestamps();
    }
}
