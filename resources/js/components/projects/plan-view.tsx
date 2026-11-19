import { useTranslation } from 'react-i18next';
import { Timeline } from '@/components/timeline';
import type {
    TimelineChip,
    TimelineDensity,
    TimelineStep,
    TimelineStepStatus,
} from '@/components/timeline';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { buildUpgradePlanView } from '@/lib/upgrade-plan';
import type { UpgradePlanTimelineStep } from '@/lib/upgrade-plan';
import type { HealthReportBody, UpgradePlanStep } from '@/types';

/** Compact density: the plan sits inside a card on the project page. */
const PLAN_TIMELINE_DENSITY: TimelineDensity = 'compact';

/**
 * Plan tab of the project page: the upgrade plan of the latest report.
 *
 * Structured steps (excubia-core 1.0.0-rc.2+) render as the optional
 * `timeline` pattern, labelled by kind with package chips. Older reports
 * without `kind` keep the plain numbered list (#333).
 */
export function PlanView({ report }: { report: HealthReportBody }) {
    const { t } = useTranslation();
    const upgradePlan: UpgradePlanStep[] = report.upgradePlan;
    const planView = buildUpgradePlanView(upgradePlan);

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.upgradePlan')}</CardTitle>
                <CardDescription>{t('report.planDescription')}</CardDescription>
            </CardHeader>
            <CardContent>
                {upgradePlan.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.noUpgradeSteps')}
                    </p>
                ) : planView.mode === 'legacy' ? (
                    <ol className="list-decimal space-y-2 pl-6 text-sm">
                        {planView.steps.map((step) => (
                            <li key={step.stepNumber}>{step.message}</li>
                        ))}
                    </ol>
                ) : (
                    <Timeline
                        aria-label={t('report.upgradePlan')}
                        density={PLAN_TIMELINE_DENSITY}
                        steps={planView.steps.map((step) =>
                            timelineStepFromPlan(step, t),
                        )}
                    />
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Maps one structured plan step to a `Timeline` row: kind label as title,
 * the core message as description, packages as chips.
 */
function timelineStepFromPlan(
    step: UpgradePlanTimelineStep,
    t: ReturnType<typeof useTranslation>['t'],
): TimelineStep {
    const status: TimelineStepStatus = step.status;
    const chips: TimelineChip[] = step.packages;

    return {
        id: step.id,
        title: kindTitle(step, t),
        description: step.message,
        status,
        chips,
        count: step.packageCount > 0 ? step.packageCount : undefined,
        countLabel:
            step.packageCount > 0
                ? t('report.plan.packageCount', { count: step.packageCount })
                : undefined,
    };
}

/**
 * Translated kind label. Blocked-major and Laravel-EOL steps include the
 * target major when core sent one; unknown kinds use a generic title.
 */
function kindTitle(
    step: UpgradePlanTimelineStep,
    t: ReturnType<typeof useTranslation>['t'],
): string {
    if (!step.isKnownKind) {
        return t('report.plan.kind.unknown');
    }

    if (step.kind === 'next-major-blocked' && step.targetMajor !== null) {
        return t('report.plan.kind.next-major-blockedWithMajor', {
            major: step.targetMajor,
        });
    }

    if (step.kind === 'laravel-eol' && step.targetMajor !== null) {
        return t('report.plan.kind.laravel-eolWithMajor', {
            major: step.targetMajor,
        });
    }

    switch (step.kind) {
        case 'composer-advisory':
            return t('report.plan.kind.composer-advisory');
        case 'npm-advisory':
            return t('report.plan.kind.npm-advisory');
        case 'php-eol':
            return t('report.plan.kind.php-eol');
        case 'next-major-blocked':
            return t('report.plan.kind.next-major-blocked');
        case 'laravel-eol':
            return t('report.plan.kind.laravel-eol');
        case 'composer-abandoned':
            return t('report.plan.kind.composer-abandoned');
        case 'composer-stale':
            return t('report.plan.kind.composer-stale');
        case 'composer-patch':
            return t('report.plan.kind.composer-patch');
        case 'npm-patch':
            return t('report.plan.kind.npm-patch');
        case 'license-review':
            return t('report.plan.kind.license-review');
        default:
            return t('report.plan.kind.unknown');
    }
}
