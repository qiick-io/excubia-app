<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;

/**
 * The filters of an alert channel or of a user's email subscription (#22): the minimum
 * severity, the event types (every event when never saved) and the projects, picked one by one
 * or by project group (#252). A project matches when it is picked or sits in a picked group, so a
 * project added to a group later is included; neither picked means every project. Stored as
 * `{minimum_severity, event_types, project_ids, project_group_ids}`.
 */
final readonly class AlertFilterData
{
    /**
     * @param  list<string>  $eventTypes
     * @param  list<int>  $projectIds  empty, with no group: every project
     * @param  list<int>  $projectGroupIds  empty, with no project: every project
     */
    public function __construct(
        public SeverityEnum $minimumSeverity,
        public array $eventTypes,
        public array $projectIds = [],
        public array $projectGroupIds = [],
    ) {}

    /**
     * Reads a stored filter; anything missing or unknown falls back to the defaults.
     */
    public static function fromStored(mixed $stored, SeverityEnum $defaultSeverity): self
    {
        $stored = is_array($stored) ? $stored : [];
        $eventTypes = $stored['event_types'] ?? null;

        return new self(
            SeverityEnum::tryFrom((string) ($stored['minimum_severity'] ?? '')) ?? $defaultSeverity,
            is_array($eventTypes) ? array_values(array_intersect(AlertEventEnum::selectableValues(), $eventTypes)) : AlertEventEnum::selectableValues(),
            self::ids($stored['project_ids'] ?? []),
            self::ids($stored['project_group_ids'] ?? []),
        );
    }

    public function accepts(AlertEventEnum $event, SeverityEnum $severity, Project $project): bool
    {
        return in_array($event->value, $this->eventTypes, true)
            && $severity->rank() <= $this->minimumSeverity->rank()
            && $this->acceptsProject($project);
    }

    /**
     * @return array{minimum_severity: string, event_types: list<string>, project_ids: list<int>, project_group_ids: list<int>}
     */
    public function toArray(): array
    {
        return [
            'minimum_severity' => $this->minimumSeverity->value,
            'event_types' => $this->eventTypes,
            'project_ids' => $this->projectIds,
            'project_group_ids' => $this->projectGroupIds,
        ];
    }

    /**
     * Reads the project's groups once (loadMissing) only when a group is picked.
     */
    private function acceptsProject(Project $project): bool
    {
        if ($this->projectIds === [] && $this->projectGroupIds === []) {
            return true;
        }

        if (in_array($project->id, $this->projectIds, true)) {
            return true;
        }

        return $this->projectGroupIds !== []
            && array_intersect($project->loadMissing('projectGroups')->projectGroups->modelKeys(), $this->projectGroupIds) !== [];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $stored): array
    {
        return array_values(array_map('intval', array_filter((array) $stored, 'is_numeric')));
    }
}
