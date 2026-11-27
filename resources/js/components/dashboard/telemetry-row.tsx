import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CardTableScroll } from '@/components/layout/card-table-scroll';
import { KpiTile, kpiDeltaOf } from '@/components/charts/kpi-tile';
import {
    historySparkline,
    OpenExceptionsKpi,
} from '@/components/dashboard/kpi-history';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import projectsRoutes from '@/routes/projects';
import type { DashboardHistory, KpiHistory, Overview } from '@/types/dashboard';

/**
 * Requests and server errors across the scoped projects for the period (#24). It joins the key
 * numbers row when there are no exceptions, so it never sits alone on a row of its own (#328).
 */
export function RequestsKpi({
    runtime,
    history,
    periodLabel,
    className,
}: {
    runtime: NonNullable<Overview['runtime']>;
    history: KpiHistory | undefined;
    periodLabel: string;
    className?: string;
}) {
    const { t, i18n } = useTranslation();
    const errorRate =
        runtime.requests > 0 ? (runtime.errors / runtime.requests) * 100 : 0;

    const delta = kpiDeltaOf(runtime.requests, runtime.previousRequests, null);
    const summary = t('dashboard.telemetry.errors', {
        count: runtime.errors,
        rate: errorRate.toLocaleString(i18n.language, {
            maximumFractionDigits: errorRate < 1 ? 2 : 1,
        }),
    });

    return (
        <KpiTile
            className={className}
            label={t('dashboard.telemetry.requests')}
            value={runtime.requests.toLocaleString(i18n.language)}
            delta={delta}
            secondary={delta ? `${summary} · ${periodLabel}` : summary}
            chart={historySparkline(history)}
        />
    );
}

/**
 * Runtime and exceptions across the scoped projects (#24, #26): requests and 5xx for the
 * period, open exception groups, and the open groups seen most recently. Rendered only when
 * there are exceptions; without requests the exceptions tile takes the whole row (#328).
 */
export function TelemetryRow({
    runtime,
    exceptions,
    history,
    periodLabel,
    periodDays,
}: {
    runtime: Overview['runtime'];
    exceptions: NonNullable<Overview['exceptions']>;
    history: DashboardHistory;
    periodLabel: string;
    periodDays: number;
}) {
    const { t } = useTranslation();

    return (
        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {runtime ? (
                <RequestsKpi
                    runtime={runtime}
                    history={history.requests}
                    periodLabel={periodLabel}
                />
            ) : null}
            <OpenExceptionsKpi
                comparisonDays={periodDays}
                className={runtime ? undefined : 'sm:col-span-2'}
                history={
                    history.exceptions ?? {
                        current: exceptions.open,
                        values: [],
                    }
                }
                secondary={t('dashboard.telemetry.newExceptions', {
                    count: exceptions.new,
                })}
            />
            {/* Under the tiles, not beside them: a list beside two tiles stretched them with
                empty space (app-ui docs/dashboard.md, "Card grids"). */}
            <Card className="sm:col-span-2">
                <CardHeader className="pb-3">
                    <CardTitle>
                        {t('dashboard.telemetry.topExceptions')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {exceptions.top.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('dashboard.telemetry.noOpenExceptions')}
                        </p>
                    ) : (
                        <CardTableScroll
                            label={t('dashboard.telemetry.topExceptions')}
                            className="max-h-48"
                        >
                            <Table>
                                <TableBody>
                                    {exceptions.top.map((group) => (
                                        <TableRow key={group.id}>
                                            <TableCell className="max-w-[24rem] min-w-0">
                                                <Link
                                                    href={projectsRoutes.view.url(
                                                        {
                                                            project:
                                                                group.project
                                                                    .slug,
                                                            view: 'exceptions',
                                                        },
                                                    )}
                                                    className="block truncate font-mono text-xs font-medium underline-offset-4 hover:underline"
                                                >
                                                    {group.class}
                                                </Link>
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {group.project.name} ·{' '}
                                                    {group.context}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {group.count.toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardTableScroll>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}
