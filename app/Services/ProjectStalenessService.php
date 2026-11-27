<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Date;

/**
 * A project is stale when it has never reported, or its last report is older than the
 * "Stale after (hours)" setting (default `excubia.stale_after_hours`). Stale projects show grey.
 */
class ProjectStalenessService
{
    public function __construct(private SettingsRepositoryService $settings) {}

    public function isStale(Project $project): bool
    {
        return $project->last_report_at === null
            || $project->last_report_at->lt(Date::now()->subHours($this->settings->staleAfterHours()));
    }
}
