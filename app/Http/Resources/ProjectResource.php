<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project for the projects list and its drawer. The token hash never leaves the server:
 * only whether a token exists.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'is_enabled' => $project->is_enabled,
            'has_push_token' => $project->push_token_hash !== null,
            'last_report_at' => $project->last_report_at?->toIso8601String(),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
