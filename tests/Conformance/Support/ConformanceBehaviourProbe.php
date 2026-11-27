<?php

declare(strict_types=1);

namespace Qiick\Standards\Conformance\Support;

/**
 * Passed into access-package behaviour hooks (C71/C73/C74). The hook must
 * exercise the real product path and call {@see pass()}; an empty no-op stays red.
 *
 * Limit: a hook that only inspects the probe (for example `instanceof` then
 * `$probe->pass()`) without exercising detach / expiry / activity stays green.
 * Human review of product hooks remains required; the probe proves presence of
 * an intentional pass call, not that the real path ran.
 */
final class ConformanceBehaviourProbe
{
    private bool $hasPassed = false;

    /**
     * Mark the behaviour as exercised and verified.
     */
    public function pass(): void
    {
        $this->hasPassed = true;
    }

    public function hasPassed(): bool
    {
        return $this->hasPassed;
    }
}
