<?php

declare(strict_types=1);

/**
 * Fleet conformance suite (qiick standards pack).
 *
 * Generated from qiick-io/app-ui `standards/php/conformance/` by
 * `scripts/sync-standards.mjs`; CI fails when this copy differs. Do not edit it
 * here. Product hooks live in `hooks.php` (repo-owned) and scalars / divergences
 * in `qiick-standards.json`.
 *
 * Runs against a freshly migrated database and the built config. Engine-dependent
 * checks must run on PostgreSQL and MySQL (see the reusable conformance workflow).
 *
 * Binding decision: knowledge/decisions/2026-11-20-core-hardening.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Qiick\Standards\Conformance\Support\ConformanceHooks;
use Qiick\Standards\Conformance\Support\ConformanceRunner;
use Qiick\Standards\Conformance\Support\DivergenceGate;
use Qiick\Standards\Conformance\Support\SchemaInspector;

/** @var list<array{id: string, tag: string, description: string, decision: string, engines?: list<string>}> $catalog */
$catalog = require __DIR__.'/catalog.php';
$knownIds = array_map(static fn (array $check): string => $check['id'], $catalog);

/*
|--------------------------------------------------------------------------
| Autoload Support classes shipped next to this suite
|--------------------------------------------------------------------------
*/
spl_autoload_register(static function (string $class): void {
    $prefix = 'Qiick\\Standards\\Conformance\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__.'/'.$relative.'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

uses(Tests\TestCase::class, RefreshDatabase::class)->group('conformance');

describe('qiick fleet conformance', function () use ($catalog, $knownIds): void {
    beforeEach(function () use ($knownIds): void {
        $basePath = base_path();

        $this->conformanceHooks = ConformanceHooks::fromBasePath($basePath);
        $this->conformanceGate = DivergenceGate::fromBasePath($basePath, $knownIds);
        $this->conformanceSchema = new SchemaInspector();
        $this->conformanceRunner = new ConformanceRunner(
            $basePath,
            $this->conformanceHooks,
            $this->conformanceSchema,
        );
    });

    foreach ($catalog as $check) {
        if ($check['id'] === 'C37') {
            continue;
        }

        it("{$check['id']}: {$check['description']}", function () use ($check): void {
            if ($this->conformanceGate->isDiverged($check['id'])) {
                $this->conformanceGate->assertDeclarationsAreValid();
                $divergence = $this->conformanceGate->for($check['id']);
                expect($divergence)->not->toBeNull();
                expect($divergence['reason'])->not->toBeEmpty();

                return;
            }

            $this->conformanceRunner->run($check);
            expect(true)->toBeTrue();
        });
    }

    it('C37: divergences are valid, not expired, not unknown, and not stale', function () use ($catalog): void {
        $this->conformanceGate->assertDeclarationsAreValid();

        $stillPassingWhileDiverged = [];

        foreach ($this->conformanceGate->declaredCheckIds() as $checkId) {
            $check = null;

            foreach ($catalog as $entry) {
                if ($entry['id'] === $checkId) {
                    $check = $entry;
                    break;
                }
            }

            if ($check === null || $checkId === 'C37') {
                continue;
            }

            try {
                $this->conformanceRunner->run($check);
                $stillPassingWhileDiverged[] = $checkId;
            } catch (\Throwable) {
                // Still red: divergence remains justified.
            }
        }

        $this->conformanceGate->assertNoStaleDeclarations($stillPassingWhileDiverged);
    });
});
