<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SeverityEnum;
use App\Models\Project;
use App\Services\ProjectListService;
use App\Services\ProjectStalenessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project for the projects list and its drawer. The token hash never leaves the server:
 * only whether a token exists. `is_stale` follows ProjectStalenessService; `latest_report` is
 * the summary of the newest report when `latestReport` is loaded; `health`, `checks` and
 * `open_findings` come with the projects list query (ProjectListService).
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
            // The list columns of ProjectListService (#293), only where its query selected them.
            ...(array_key_exists('health_rank', $project->getAttributes()) ? $this->listColumns($project) : []),
            'latest_report' => $this->whenLoaded('latestReport', fn (): ?array => $project->latestReport === null ? null : (new HealthReportResource($project->latestReport))->resolve()),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Health is the worst check status of the latest report (null without one, or when every
     * check was skipped); `checks` is null without a report.
     *
     * @return array{health: string|null, checks: array{failed: int, total: int}|null, open_findings: array<string, int>}
     */
    private function listColumns(Project $project): array
    {
        $openFindings = [];

        foreach (SeverityEnum::values() as $severity) {
            $openFindings[$severity] = (int) $project->getAttribute("open_{$severity}_count");
        }

        $checksCount = $project->getAttribute('checks_count');

        return [
            'health' => array_search((int) $project->getAttribute('health_rank'), ProjectListService::HEALTH_RANKS, true) ?: null,
            'checks' => $checksCount === null ? null : [
                'failed' => (int) $project->getAttribute('failed_checks_count'),
                'total' => (int) $checksCount,
            ],
            'open_findings' => $openFindings,
        ];
    }
}
