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

/**
 * The one severity scale (#204): each severity's badge variant and its chart colour, the
 * `--severity-*` token in app.css that points at the same status token as the badge (critical
 * destructive, high warning, medium info; low and info neutral, low the stronger grey). Charts and
 * badges read it from here, so a severity looks the same everywhere.
 */
export const SEVERITY_SCALE: Record<
    Severity,
    {
        badge: NonNullable<VariantProps<typeof badgeVariants>['variant']>;
        chartColor: string;
    }
> = {
    critical: { badge: 'destructive', chartColor: 'var(--severity-critical)' },
    high: { badge: 'warning', chartColor: 'var(--severity-high)' },
    medium: { badge: 'info', chartColor: 'var(--severity-medium)' },
    low: { badge: 'secondary', chartColor: 'var(--severity-low)' },
    info: { badge: 'muted', chartColor: 'var(--severity-info)' },
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
        <Badge variant={SEVERITY_SCALE[severity].badge}>
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
