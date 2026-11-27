<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ScheduledTaskStatusEnum;
use Carbon\CarbonImmutable;

/**
 * The status of one scheduled task (#110): `dueAt` is the due time it was judged on (null when
 * none), `nextDueAt` the next one after now (UTC). `isLowConfidence` marks a missing run that
 * sampling (`sampleRate` below 1) or an unknown environment can explain: shown, never alerted.
 */
final readonly class ScheduledTaskEvaluationData
{
    public function __construct(
        public ScheduledTaskStatusEnum $status,
        public ?CarbonImmutable $dueAt = null,
        public ?CarbonImmutable $nextDueAt = null,
        public bool $isLowConfidence = false,
    ) {}
}
