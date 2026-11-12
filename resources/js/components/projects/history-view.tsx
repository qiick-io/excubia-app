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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { HealthReportSummary } from '@/types';

/**
 * History tab of the project page: the findings per severity of every stored report as a
 * chart (oldest first) and the table of the reports.
 */
export function HistoryView({ reports }: { reports: HealthReportSummary[] }) {
    const { t } = useTranslation();

    if (reports.length === 0) {
        return (
            <PageEmptyState
                icon={History}
                title={t('dashboard.noReports')}
                description={t('report.empty')}
            />
        );
    }

    // Oldest first, so the chart reads left to right in time.
    const chartData = [...reports].reverse().map((summary) => ({
        label: new Date(summary.received_at).toLocaleString(undefined, {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        }),
        ...summary.counts,
    }));
    const latestTotal = SEVERITIES.reduce(
        (total, severity) => total + reports[0].counts[severity],
        0,
    );

    const reportColumns: ColumnDef<HealthReportSummary>[] = [
        {
            id: 'receivedAt',
            header: t('report.receivedAt'),
            meta: { cellClassName: 'tabular-nums' },
            cell: ({ row }) => {
                const summary = row.original;

                return <>{new Date(summary.received_at).toLocaleString()}</>;
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
                    <CardDescription>
                        {t('report.trend.description')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="h-64">
                    <SeverityStackedBarChart
                        data={chartData}
                        label={t('report.trend.label', {
                            count: reports.length,
                            total: latestTotal,
                        })}
                    />
                </CardContent>
            </Card>
            <DataTable
                columns={reportColumns}
                data={reports}
                getRowId={(summary) => String(summary.id)}
            />
        </div>
    );
}
