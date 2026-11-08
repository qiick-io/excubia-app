<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use App\Services\ProjectStalenessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project for the projects list and its drawer. The token hash never leaves the server:
 * only whether a token exists. `is_stale` follows ProjectStalenessService; `latest_report` is
 * the summary of the newest report when `latestReport` is loaded.
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
            'is_system' => $project->is_system,
            'visibility' => $project->visibility->value,
            'has_push_token' => $project->push_token_hash !== null,
            'last_report_at' => $project->last_report_at?->toIso8601String(),
            'environment' => $project->environment,
            'is_stale' => app(ProjectStalenessService::class)->isStale($project),
            'latest_report' => $this->whenLoaded('latestReport', fn (): ?array => $project->latestReport === null ? null : (new HealthReportResource($project->latestReport))->resolve()),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
