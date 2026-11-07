<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AlertLog;
use App\Models\AlertMute;
use App\Models\MaintenanceWindow;
use App\Models\Project;

/**
 * The project's Alerts tab (#114): the alert history (sent, failed, suppressed with its reason,
 * skipped), the mutes in force, the maintenance windows and whether alerts are paused right
 * now. Times are ISO 8601 UTC.
 */
class ProjectAlertsService
{
    /** History rows on the tab. */
    public const HISTORY_LIMIT = 100;

    public function __construct(
        private AlertSuppressionService $suppression,
        private ReportFindingsService $findings,
    ) {}

    /**
     * @return array{history: list<array<string, mixed>>, mutes: list<array<string, mixed>>, windows: list<array<string, mixed>>, isInMaintenanceMode: bool}
     */
    public function forPage(Project $project): array
    {
        // ponytail: the latest HISTORY_LIMIT alerts; paginate if a project needs more.
        $history = AlertLog::query()
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (AlertLog $alert): array => [
                'id' => $alert->id,
                'event' => $alert->event,
                'severity' => $alert->severity,
                'summary' => $alert->summary,
                'has_subject' => $alert->subject !== null,
                'status' => $alert->status,
                'reason' => $alert->reason,
                'deliveries' => (object) $alert->deliveries,
                'created_at' => $alert->created_at->toIso8601String(),
            ])
            ->all();

        $mutes = AlertMute::query()
            ->active()
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AlertMute $mute): array => [
                'id' => $mute->id,
                'event' => $mute->event,
                'is_issue' => $mute->subject !== null,
                'label' => $mute->label,
                'until' => $mute->until?->toIso8601String(),
            ])
            ->all();

        $windows = MaintenanceWindow::query()
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->get()
            ->map(fn (MaintenanceWindow $window): array => [
                'id' => $window->id,
                'name' => $window->name,
                'recurrence' => $window->recurrence,
                'timezone' => $window->timezone,
                'starts_at' => $window->starts_at?->toIso8601String(),
                'ends_at' => $window->ends_at?->toIso8601String(),
                'weekdays' => $window->weekdays ?? [],
                'start_time' => $window->start_time,
                'duration_minutes' => $window->duration_minutes,
                'is_active' => $this->suppression->isWindowActive($window),
            ])
            ->all();

        return [
            'history' => array_values($history),
            'mutes' => array_values($mutes),
            'windows' => array_values($windows),
            'isInMaintenanceMode' => $this->findings->isInMaintenanceMode($project),
        ];
    }
}
