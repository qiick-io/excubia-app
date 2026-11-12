import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { StatCard, trendOf } from '@/components/stat-card';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import projectsRoutes from '@/routes/projects';
import type { Overview } from '@/types/dashboard';

/**
 * Runtime and exceptions across the scoped projects (#24, #26): requests and 5xx for the
 * period, open exception groups, and the open groups seen most recently.
 */
export function TelemetryRow({
    runtime,
    exceptions,
    periodLabel,
}: {
    runtime: Overview['runtime'];
    exceptions: Overview['exceptions'];
    periodLabel: string;
}) {
    const { t } = useTranslation();
    const errorRate =
        runtime && runtime.requests > 0
            ? (runtime.errors / runtime.requests) * 100
            : 0;

    return (
        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
            {runtime ? (
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.telemetry.requests')}
                    value={runtime.requests.toLocaleString()}
                    trend={trendOf(runtime.requests, runtime.previousRequests)}
                    headline={t('dashboard.telemetry.errors', {
                        count: runtime.errors,
                        rate: errorRate.toFixed(errorRate < 1 ? 2 : 1),
                    })}
                    context={periodLabel}
                />
            ) : null}
            {exceptions ? (
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.telemetry.openExceptions')}
                    value={exceptions.open.toLocaleString()}
                    headline={t('dashboard.telemetry.newExceptions', {
                        count: exceptions.new,
                    })}
                    context={t('dashboard.telemetry.openExceptionsContext')}
                />
            ) : null}
            {exceptions ? (
                <Card className="sm:col-span-2 xl:col-span-6">
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
                            <div className="max-h-48 overflow-auto">
                                <Table>
                                    <TableBody>
                                        {exceptions.top.map((group) => (
                                            <TableRow key={group.id}>
                                                <TableCell className="max-w-[24rem] min-w-0">
                                                    <Link
                                                        href={projectsRoutes.view.url(
                                                            {
                                                                project:
                                                                    group
                                                                        .project
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
                            </div>
                        )}
                    </CardContent>
                </Card>
            ) : null}
        </section>
    );
}
