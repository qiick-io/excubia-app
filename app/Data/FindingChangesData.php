<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SeverityEnum;

/**
 * What one report changed in a project's finding lifecycle (#18). The lists hold report rows
 * (with `checkName`) in report order and leave out findings with an active dismissal:
 * - `new`: seen for the first time, or back after a report had resolved them (regressions);
 * - `resolved`: gone from this report while their check ran.
 * `worstBefore` / `worstAfter` are the worst severity of the open, not dismissed findings before
 * and after the report, so a push with fewer checks does not look like an improvement.
 */
final readonly class FindingChangesData
{
    /**
     * @param  list<array<string, mixed>>  $new
     * @param  list<array<string, mixed>>  $resolved
     */
    public function __construct(
        public array $new = [],
        public array $resolved = [],
        public ?SeverityEnum $worstBefore = null,
        public ?SeverityEnum $worstAfter = null,
    ) {}
}
