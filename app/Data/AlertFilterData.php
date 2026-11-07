<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\AlertEventEnum;
use App\Enums\SeverityEnum;
use App\Models\Project;

/**
 * The three filters of an alert channel or of a user's email subscription (#22): the minimum
 * severity, the event types (every event when never saved) and the projects (every project
 * when the list is empty). Stored as `{minimum_severity, event_types, project_ids}`.
 */
final readonly class AlertFilterData
{
    /**
     * @param  list<string>  $eventTypes
     * @param  list<int>  $projectIds  empty: every project
     */
    public function __construct(
        public SeverityEnum $minimumSeverity,
        public array $eventTypes,
        public array $projectIds = [],
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
            array_values(array_map('intval', array_filter((array) ($stored['project_ids'] ?? []), 'is_numeric'))),
        );
    }

    public function accepts(AlertEventEnum $event, SeverityEnum $severity, Project $project): bool
    {
        return in_array($event->value, $this->eventTypes, true)
            && $severity->rank() <= $this->minimumSeverity->rank()
            && ($this->projectIds === [] || in_array($project->id, $this->projectIds, true));
    }

    /**
     * @return array{minimum_severity: string, event_types: list<string>, project_ids: list<int>}
     */
    public function toArray(): array
    {
        return ['minimum_severity' => $this->minimumSeverity->value, 'event_types' => $this->eventTypes, 'project_ids' => $this->projectIds];
    }
}
