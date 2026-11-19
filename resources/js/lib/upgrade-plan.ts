/**
 * View model for the project upgrade-plan tab (#333).
 *
 * Old reports (no `kind`) stay a numbered list. Structured steps from
 * excubia-core 1.0.0-rc.2+ become timeline rows labelled by kind, with
 * package chips. Unknown kinds stay generic so a newer core never crashes
 * the page. No React imports: `node --test` can load this file directly.
 */

/** Kind values shipped by excubia-core (`UpgradeStepKindEnum`). */
export const UPGRADE_PLAN_KINDS = [
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
] as const;

type UpgradePlanKind = (typeof UPGRADE_PLAN_KINDS)[number];

type UpgradePlanPackage = {
    name: string;
    ecosystem: 'composer' | 'npm';
    installed: string | null;
    target: string | null;
};

type UpgradePlanStep = {
    stepNumber: number;
    message: string;
    kind?: string | null;
    targetMajor?: number | null;
    packages?: UpgradePlanPackage[];
};

/** Matches `TimelineStepStatus` without importing the React component. */
type UpgradePlanStepStatus =
    | 'done'
    | 'current'
    | 'pending'
    | 'warning'
    | 'blocked';

export type UpgradePlanPackageChip = {
    id: string;
    label: string;
    value?: string;
    previousValue?: string;
};

export type UpgradePlanTimelineStep = {
    id: string;
    kind: string;
    isKnownKind: boolean;
    status: UpgradePlanStepStatus;
    targetMajor: number | null;
    message: string;
    packages: UpgradePlanPackageChip[];
    packageCount: number;
};

export type UpgradePlanView =
    | { mode: 'legacy'; steps: { stepNumber: number; message: string }[] }
    | { mode: 'timeline'; steps: UpgradePlanTimelineStep[] };

const KNOWN_KIND_SET: ReadonlySet<string> = new Set(UPGRADE_PLAN_KINDS);

/**
 * Status colour / urgency for each known kind. Unknown kinds stay `pending`
 * so the marker stays neutral.
 */
const KIND_STATUS: Record<UpgradePlanKind, UpgradePlanStepStatus> = {
    'composer-advisory': 'warning',
    'npm-advisory': 'warning',
    'php-eol': 'warning',
    'next-major-blocked': 'blocked',
    'laravel-eol': 'warning',
    'composer-abandoned': 'warning',
    'composer-stale': 'warning',
    'composer-patch': 'pending',
    'npm-patch': 'pending',
    'license-review': 'warning',
};

/**
 * Whether this report already carries structured upgrade steps from core
 * rc.2+. A single step with `kind` is enough: mixed plans are not a thing
 * cores emit, and one structured step should still use the timeline.
 */
function hasStructuredUpgradePlan(steps: readonly UpgradePlanStep[]): boolean {
    return steps.some(
        (step) => typeof step.kind === 'string' && step.kind.length > 0,
    );
}

/**
 * One package as a timeline chip. With a target, shows installed → target
 * when installed is present; with a null target, shows installed only.
 */
export function packageChipFromUpgradePackage(
    upgradePackage: UpgradePlanPackage,
    packageIndex: number,
): UpgradePlanPackageChip {
    const chip: UpgradePlanPackageChip = {
        id: `${upgradePackage.ecosystem}:${upgradePackage.name}:${packageIndex}`,
        label: upgradePackage.name,
    };

    if (upgradePackage.target !== null && upgradePackage.target !== '') {
        chip.value = upgradePackage.target;

        if (
            upgradePackage.installed !== null &&
            upgradePackage.installed !== ''
        ) {
            chip.previousValue = upgradePackage.installed;
        }

        return chip;
    }

    if (upgradePackage.installed !== null && upgradePackage.installed !== '') {
        chip.value = upgradePackage.installed;
    }

    return chip;
}

/**
 * Turns the report's `upgradePlan` into either the legacy numbered list or
 * timeline rows. Callers translate kind labels with i18n.
 */
export function buildUpgradePlanView(
    steps: readonly UpgradePlanStep[],
): UpgradePlanView {
    if (!hasStructuredUpgradePlan(steps)) {
        return {
            mode: 'legacy',
            steps: steps.map((step) => ({
                stepNumber: step.stepNumber,
                message: step.message,
            })),
        };
    }

    return {
        mode: 'timeline',
        steps: steps.map((step, stepIndex) => {
            const kind =
                typeof step.kind === 'string' && step.kind.length > 0
                    ? step.kind
                    : 'unknown';
            const isKnownKind = KNOWN_KIND_SET.has(kind);
            const packages = (step.packages ?? []).map(
                packageChipFromUpgradePackage,
            );

            return {
                id: `step-${step.stepNumber}-${stepIndex}`,
                kind,
                isKnownKind,
                status: isKnownKind
                    ? KIND_STATUS[kind as UpgradePlanKind]
                    : 'pending',
                targetMajor:
                    typeof step.targetMajor === 'number'
                        ? step.targetMajor
                        : null,
                message: step.message,
                packages,
                packageCount: packages.length,
            };
        }),
    };
}
