import { useTranslation } from 'react-i18next';
import { SegmentedBar } from '@/components/charts/segmented-bar';
import { SEVERITIES } from '@/components/reports/severity-badge';
import { Card } from '@/components/ui/card';
import type { HealthReportSummary } from '@/types';

const OUTCOMES = ['fail', 'warn', 'pass', 'skipped'] as const;

/** The counts behind the findings and checks KPIs, in worst-first semantic order. */
export function ReportCompositions({
    findings,
    checks,
}: {
    findings: HealthReportSummary['counts'];
    checks: HealthReportSummary['checks'];
}) {
    const { t } = useTranslation();

    return (
        <Card className="gap-0 py-0">
            <div className="grid gap-6 p-4 md:grid-cols-2">
                <section className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium">
                        {t('charts.findingsBySeverity')}
                    </h2>
                    <SegmentedBar
                        title={t('charts.findingsBySeverity')}
                        segments={SEVERITIES.map((severity) => ({
                            label: t(`report.severity.${severity}`),
                            value: findings[severity],
                            tone: severity,
                        }))}
                    />
                </section>
                <section className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium">
                        {t('charts.checkOutcomes')}
                    </h2>
                    <SegmentedBar
                        title={t('charts.checkOutcomes')}
                        segments={OUTCOMES.map((outcome) => ({
                            label: t(`report.status.${outcome}`),
                            value: checks[outcome],
                            tone: outcome,
                        }))}
                    />
                </section>
            </div>
        </Card>
    );
}
