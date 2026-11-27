import type { ColumnDef } from '@tanstack/react-table';
import { History, Rocket } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { DataTable } from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { ReleaseLabel } from '@/components/reports/release-label';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
} from '@/components/reports/severity-badge';
import { SeverityStackedBarChart } from '@/components/reports/severity-chart';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { HealthReportSummary } from '@/types';
import type { Paginated } from '@/types/admin';
import { useDateFormat } from '@/lib/date-format';

export type HistoryReport = Pick<
    HealthReportSummary,
    | 'id'
    | 'received_at'
    | 'release'
    | 'deployed_at'
    | 'worst_severity'
    | 'counts'
    | 'excubia_version'
>;

export type HistoryChartPoint = Pick<HistoryReport, 'received_at' | 'counts'>;

/**
 * The latest reports form a bounded chart while the table can page through all stored reports.
 */
export function HistoryView({
    reports,
    chart,
}: {
    reports: Paginated<HistoryReport>;
    chart: HistoryChartPoint[];
}) {
    const { t } = useTranslation();
    const dates = useDateFormat();

    if (reports.total === 0) {
        return (
            <PageEmptyState
                icon={History}
                title={t('dashboard.noReports')}
                description={t('report.empty')}
            />
        );
    }

    // Oldest first, so the chart reads left to right in time.
    const chartData = [...chart].reverse().map((summary) => ({
        label: dates.format(summary.received_at, {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        }),
        ...summary.counts,
    }));
    const latestTotal = SEVERITIES.reduce(
        (total, severity) => total + (chart[0]?.counts[severity] ?? 0),
        0,
    );

    const reportColumns: ColumnDef<HistoryReport>[] = [
        {
            id: 'receivedAt',
            header: t('report.receivedAt'),
            meta: { cellClassName: 'tabular-nums' },
            cell: ({ row }) => {
                const summary = row.original;

                return <>{dates.dateTime(summary.received_at)}</>;
            },
        },
        {
            id: 'worstSeverity',
            header: t('report.worstSeverity'),
            cell: ({ row }) => {
                const summary = row.original;

                return (
                    <>
                        <SeverityBadge severity={summary.worst_severity} />
                    </>
                );
            },
        },
        {
            id: 'findings',
            header: t('report.findings'),
            cell: ({ row }) => {
                const summary = row.original;

                return (
                    <>
                        <SeverityCounts counts={summary.counts} />
                    </>
                );
            },
        },
        {
            id: 'release',
            header: t('report.release'),
            cell: ({ row }) => {
                const summary = row.original;

                return (
                    <>
                        <div className="flex items-center gap-2 text-xs">
                            {summary.release === null ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                <ReleaseLabel release={summary.release} />
                            )}
                            {summary.deployed_at !== null ? (
                                <Badge variant="info">
                                    <Rocket />
                                    {t('report.deploy')}
                                </Badge>
                            ) : null}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'coreVersionShort',
            header: t('report.coreVersionShort'),
            meta: {
                headerClassName: 'text-right',
                cellClassName:
                    'text-right font-mono text-xs text-muted-foreground',
            },
            cell: ({ row }) => {
                const summary = row.original;

                return <>{summary.excubia_version ?? '—'}</>;
            },
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle>{t('report.trend.title')}</CardTitle>
                </CardHeader>
                <CardContent className="h-64">
                    <SeverityStackedBarChart
                        data={chartData}
                        label={t('report.trend.label', {
                            count: chart.length,
                            total: latestTotal,
                        })}
                    />
                </CardContent>
            </Card>
            <DataTable
                columns={reportColumns}
                data={reports.data}
                pagination={reports}
                getRowId={(summary) => String(summary.id)}
            />
        </div>
    );
}
