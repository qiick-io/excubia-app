import { ChartNoAxesCombined } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EntityBarChart } from '@/components/charts/entity-bar-chart';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import { Card } from '@/components/ui/card';
import { formatInstant } from '@/lib/date-format';
import { findingsCount } from '@/lib/report-format';
import type { ProjectRow } from '@/types';
import type { TrendPoint } from '@/types/dashboard';

export const MAX_PROJECTS_IN_CHART = 8;

/** A fleet trend over the selected period and, for a small fleet, a comparison by project. */
export function FleetFindings({
    projects,
    trend,
}: {
    projects: ProjectRow[];
    trend: TrendPoint[];
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
    const rows = trend.map((point) => ({
        date: point.date,
        findings: findingsCount(point),
    }));
    const entities = projects
        .flatMap(({ name, latest_report: report }) =>
            report
                ? [{ label: name, value: findingsCount(report.counts) }]
                : [],
        )
        .sort((first, second) => second.value - first.value);
    const hasComparison =
        entities.length >= 3 &&
        entities.length <= MAX_PROJECTS_IN_CHART &&
        entities.some(({ value }) => value !== entities[0].value);
    const dailyFindings = t('report.overview.findings');
    const comparison = t('charts.findingsByProject');
    const formatCount = (value: number) => value.toLocaleString(locale);
    const formatDay = (day: string) =>
        formatInstant(`${day}T00:00:00Z`, locale, 'UTC', {
            day: 'numeric',
            month: 'short',
        });

    return (
        <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <TimeSeriesChart
                className={hasComparison ? 'xl:col-span-8' : 'xl:col-span-12'}
                title={t('dashboard.trend.title')}
                series={[{ key: 'findings', label: dailyFindings }]}
                metrics={[
                    {
                        key: 'findings',
                        label: dailyFindings,
                        rows: rows.length >= 3 ? rows : [],
                        formatValue: formatCount,
                    },
                ]}
                formatDate={formatDay}
                metricSwitchLabel={t('charts.metric')}
                dateColumnHeader={t('charts.date')}
                legendValue="latest"
                emptyState={
                    <PageEmptyState
                        compact
                        icon={ChartNoAxesCombined}
                        title={t('dashboard.trend.historyBuilding')}
                        description={t('charts.findingsHistoryEmpty')}
                    />
                }
            />
            {hasComparison ? (
                <Card className="gap-4 py-4 xl:col-span-4">
                    <h2 className="px-4 text-sm font-medium">{comparison}</h2>
                    <div className="px-4">
                        <EntityBarChart
                            title={comparison}
                            valueLabel={t('report.overview.findings')}
                            entityColumnHeader={t('fleet.project')}
                            entities={entities}
                            formatValue={formatCount}
                        />
                    </div>
                </Card>
            ) : null}
        </section>
    );
}
