<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MaintenanceWindow;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * Adds a maintenance window to a project (#114). A one-off window's local start and end are
 * read in its timezone and stored in UTC; a weekly one keeps its local start time.
 */
class CreateMaintenanceWindowAction
{
    /**
     * @param  array{name: string, recurrence: string, timezone: string, starts_at?: string|null, ends_at?: string|null, weekdays?: list<int>|null, start_time?: string|null, duration_minutes?: int|null}  $values
     */
    public function execute(Project $project, array $values): MaintenanceWindow
    {
        $isOnce = $values['recurrence'] === MaintenanceWindow::ONCE;
        $utc = fn (?string $local): ?CarbonImmutable => $local === null ? null : CarbonImmutable::parse($local, $values['timezone'])->utc();

        return MaintenanceWindow::query()->create([
            'project_id' => $project->id,
            'name' => $values['name'],
            'recurrence' => $values['recurrence'],
            'timezone' => $values['timezone'],
            'starts_at' => $isOnce ? $utc($values['starts_at'] ?? null) : null,
            'ends_at' => $isOnce ? $utc($values['ends_at'] ?? null) : null,
            'weekdays' => $isOnce ? null : array_values(array_unique(array_map('intval', $values['weekdays'] ?? []))),
            'start_time' => $isOnce ? null : ($values['start_time'] ?? null),
            'duration_minutes' => $isOnce ? null : ($values['duration_minutes'] ?? null),
        ]);
    }
}
