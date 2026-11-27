/**
 * Cases for the upgrade-plan timeline (#333): legacy list, unknown kind,
 * installed-only chips, and a normal structured report.
 *
 * Lives under scripts/ so `tsc` does not type-check it; Node strips the
 * types of the lib on import (same pattern as app-ui's timeline.test.mjs).
 */
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import {
    UPGRADE_PLAN_KINDS,
    buildUpgradePlanView,
    packageChipFromUpgradePackage,
} from '../resources/js/lib/upgrade-plan.ts';

void describe('buildUpgradePlanView', () => {
    void test('old reports with no kind keep the plain numbered message list', () => {
        const view = buildUpgradePlanView([
            { stepNumber: 1, message: 'composer update guzzlehttp/guzzle' },
            { stepNumber: 2, message: 'Upgrade PHP to 8.5' },
        ]);

        assert.equal(view.mode, 'legacy');

        if (view.mode !== 'legacy') {
            return;
        }

        assert.deepEqual(view.steps, [
            { stepNumber: 1, message: 'composer update guzzlehttp/guzzle' },
            { stepNumber: 2, message: 'Upgrade PHP to 8.5' },
        ]);
    });

    void test('an unknown kind becomes a generic timeline step without throwing', () => {
        const view = buildUpgradePlanView([
            {
                stepNumber: 1,
                message: 'Future core step about widgets',
                kind: 'widget-review',
                targetMajor: null,
                packages: [
                    {
                        name: 'acme/widget',
                        ecosystem: 'composer',
                        installed: '1.0.0',
                        target: null,
                    },
                ],
            },
        ]);

        assert.equal(view.mode, 'timeline');

        if (view.mode !== 'timeline') {
            return;
        }

        assert.equal(view.steps.length, 1);
        assert.equal(view.steps[0]?.kind, 'widget-review');
        assert.equal(view.steps[0]?.isKnownKind, false);
        assert.equal(view.steps[0]?.status, 'pending');
        assert.equal(view.steps[0]?.message, 'Future core step about widgets');
        assert.equal(view.steps[0]?.packages.length, 1);
    });

    void test('a null target shows the installed version only', () => {
        const chip = packageChipFromUpgradePackage(
            {
                name: 'guzzlehttp/guzzle',
                ecosystem: 'composer',
                installed: '7.9.0',
                target: null,
            },
            0,
        );

        assert.equal(chip.label, 'guzzlehttp/guzzle');
        assert.equal(chip.value, '7.9.0');
        assert.equal(chip.previousValue, undefined);
    });

    void test('a structured report maps kinds, status and installed → target chips', () => {
        const view = buildUpgradePlanView([
            {
                stepNumber: 1,
                message: 'Update composer packages to the latest patch: …',
                kind: 'composer-patch',
                targetMajor: null,
                packages: [
                    {
                        name: 'laravel/pint',
                        ecosystem: 'composer',
                        installed: 'v1.32.2',
                        target: 'v1.32.3',
                    },
                    {
                        name: 'spatie/laravel-activitylog',
                        ecosystem: 'composer',
                        installed: '5.1.4',
                        target: '5.1.5',
                    },
                ],
            },
            {
                stepNumber: 2,
                message: 'No release supports Laravel 14 yet.',
                kind: 'next-major-blocked',
                targetMajor: 14,
                packages: [
                    {
                        name: 'laravel/horizon',
                        ecosystem: 'composer',
                        installed: '5.29.0',
                        target: null,
                    },
                ],
            },
        ]);

        assert.equal(view.mode, 'timeline');

        if (view.mode !== 'timeline') {
            return;
        }

        assert.equal(view.steps.length, 2);

        const patchStep = view.steps[0];
        assert.equal(patchStep?.kind, 'composer-patch');
        assert.equal(patchStep?.isKnownKind, true);
        assert.equal(patchStep?.status, 'pending');
        assert.equal(patchStep?.packages.length, 2);
        assert.equal(patchStep?.packages[0]?.previousValue, 'v1.32.2');
        assert.equal(patchStep?.packages[0]?.value, 'v1.32.3');
        assert.equal(patchStep?.packageCount, 2);

        const blockedStep = view.steps[1];
        assert.equal(blockedStep?.kind, 'next-major-blocked');
        assert.equal(blockedStep?.status, 'blocked');
        assert.equal(blockedStep?.targetMajor, 14);
        assert.equal(blockedStep?.packages[0]?.value, '5.29.0');
        assert.equal(blockedStep?.packages[0]?.previousValue, undefined);
    });

    void test('every known kind from core is recognised', () => {
        assert.deepEqual(
            [...UPGRADE_PLAN_KINDS],
            [
                'composer-advisory',
                'npm-advisory',
                'php-eol',
                'next-major-blocked',
                'laravel-eol',
                'composer-abandoned',
                'composer-stale',
                'composer-patch',
                'npm-patch',
                'license-review',
            ],
        );
    });
});
