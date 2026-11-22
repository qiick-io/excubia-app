<?php

declare(strict_types=1);

/**
 * Product-owned behaviour hooks for the fleet conformance suite.
 *
 * The sync writes this file once and never overwrites or checks it: it is the
 * repo's own. Scalar product settings (user model, table names, feature flags)
 * live in `qiick-standards.json` → `conformance`. Callables that exercise the
 * real app (soft-deleted groups, erasure, SSRF, …) go here.
 *
 * Each key matches a check that calls `ConformanceHooks::requireCallable()`.
 * Omit a key until the product is ready; the check fails until you implement
 * it or declare a divergence in `qiick-standards.json`.
 *
 * @return array<string, callable(): void>
 */
return [
    // 'assertSoftDeletedGroupGrantsNothing' => function (): void { ... },
    // 'assertGlobalRoleGuard' => function (): void { ... },
    // 'assertApiKeyActivityUuid' => function (): void { ... },
    // 'assertErasure' => function (): void { ... },
    // 'assertInitialAdminSeeder' => function (): void { ... },
    // 'assertErrorContract' => function (): void { ... },
    // 'assertContentLanguage' => function (): void { ... },
    // 'assertPaginationShape' => function (): void { ... },
    // 'assertThrottleHeaders' => function (): void { ... },
    // 'assertRequestId' => function (): void { ... },
    // 'assertIdempotency' => function (): void { ... },
    // 'assertWebhooks' => function (): void { ... },
    // 'assertSsrfGuard' => function (): void { ... },
    // 'assertTeamSwitch' => function (): void { ... },
    // 'assertSuperAdmin' => function (): void { ... },
    // 'assertApiResourcesHideBigints' => function (): void { ... },
    // 'assertEncryptedCasts' => function (): void { ... },
];
