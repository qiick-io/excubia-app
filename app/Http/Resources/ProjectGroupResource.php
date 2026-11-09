<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\ProjectGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A project group with the projects the actor can see, for the admin list and the group drawer.
 * The controller loads `projects` already filtered by Project::visibleTo().
 *
 * @mixin ProjectGroup
 */
class ProjectGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectGroup $projectGroup */
        $projectGroup = $this->resource;

        return [
            'id' => $projectGroup->id,
            'name' => $projectGroup->name,
            'slug' => Str::slug($projectGroup->name),
            'description' => $projectGroup->description,
            'projects' => $this->whenLoaded('projects', fn (): array => $projectGroup->projects
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
                ->values()
                ->all(), []),
            'can_update' => Gate::allows('update', $projectGroup),
            'can_delete' => Gate::allows('delete', $projectGroup),
            'created_at' => $projectGroup->created_at?->toIso8601String(),
            'updated_at' => $projectGroup->updated_at?->toIso8601String(),
        ];
    }
}
