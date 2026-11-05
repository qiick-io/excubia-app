import type { VariantProps } from 'class-variance-authority';
import { useTranslation } from 'react-i18next';
import { Badge, badgeVariants } from '@/components/ui/badge';
import type { Severity } from '@/types';

export const SEVERITIES: Severity[] = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
];

const SEVERITY_VARIANTS: Record<
    Severity,
    NonNullable<VariantProps<typeof badgeVariants>['variant']>
> = {
    critical: 'destructive',
    high: 'warning',
    medium: 'info',
    low: 'secondary',
    info: 'muted',
};

/**
 * Severity of a finding or a report. `null` means the report has no finding that warns or
 * fails. The text always names the severity, so colour is never the only signal.
 */
export function SeverityBadge({
    severity,
    count,
}: {
    severity: Severity | null;
    count?: number;
}) {
    const { t } = useTranslation();

    if (severity === null) {
        return <Badge variant="success">{t('report.noIssues')}</Badge>;
    }

    return (
        <Badge variant={SEVERITY_VARIANTS[severity]}>
            {count === undefined
                ? t(`report.severity.${severity}`)
                : `${count} ${t(`report.severity.${severity}`)}`}
        </Badge>
    );
}

/**
 * The non-zero severity counts of a report, worst first.
 */
export function SeverityCounts({
    counts,
}: {
    counts: Record<Severity, number>;
}) {
    return (
        <div className="flex flex-wrap gap-1">
            {SEVERITIES.filter((severity) => counts[severity] > 0).map(
                (severity) => (
                    <SeverityBadge
                        key={severity}
                        severity={severity}
                        count={counts[severity]}
                    />
                ),
            )}
        </div>
    );
}

/**
 * "11 medium · 3 low": the non-zero severities, worst first.
 */
export function severitySummary(
    counts: Record<Severity, number>,
    t: (key: string) => string,
): string {
    const parts = SEVERITIES.filter((severity) => counts[severity] > 0).map(
        (severity) =>
            `${counts[severity]} ${t(`report.severity.${severity}`).toLowerCase()}`,
    );

    return parts.length === 0 ? t('report.noIssues') : parts.join(' · ');
}
