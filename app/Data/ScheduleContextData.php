<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * What the schedule evaluation (#110) knows about a project at `now`:
 * - `environment`: the app environment, from its latest exception group (the report carries
 *   none); null when unknown;
 * - `isInMaintenanceMode`: the latest report has a `laravel-maintenance-mode` finding;
 * - `sampleRate`: the rate of the project's newest scheduled-task run, null without runs;
 * - `runsSince`: the oldest run bucket with a `taskId` on record, so due times before the app
 *   had run data are never judged; null when it has none yet.
 */
final readonly class ScheduleContextData
{
    public function __construct(
        public CarbonImmutable $now,
        public ?string $environment = null,
        public bool $isInMaintenanceMode = false,
        public ?float $sampleRate = null,
        public ?CarbonImmutable $runsSince = null,
    ) {}
}
