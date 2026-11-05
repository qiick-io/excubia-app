import { Gauge } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { StackedBarChart } from '@/components/reports/severity-chart';
import { StatCard } from '@/components/reports/stat-card';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { RuntimeRow, RuntimeSummary } from '@/types';

const RUNTIME_GUIDE_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/docs/guides/runtime-monitoring.md';

/**
 * The runtime of a project over the last 24 hours (#24), Pulse-style: key numbers, requests per
 * hour (successful vs 5xx), then tables per route, slow query, job, scheduled task and outgoing
 * host. Estimated counts carry a `~` when the project samples.
 */
export function RuntimeView({ runtime }: { runtime: RuntimeSummary | null }) {
    const { t } = useTranslation();

    if (runtime === null) {
        return (
            <PageEmptyState
                icon={Gauge}
                title={t('runtime.emptyTitle')}
                description={t('runtime.emptyDescription')}
                docsHref={RUNTIME_GUIDE_URL}
                docsLabel={t('runtime.guide')}
            />
        );
    }

    const approx = runtime.isSampled ? '~' : '';
    const count = (value: number): string =>
        `${approx}${value.toLocaleString()}`;
    const ms = (value: number | null): string =>
        value === null ? '—' : `${Math.round(value).toLocaleString()} ms`;
    const { requests } = runtime;
    const errorRate =
        requests.count > 0 ? (requests.errors / requests.count) * 100 : 0;

    return (
        <div className="flex flex-col gap-4">
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.requests')}
                    value={count(requests.count)}
                    headline={t('runtime.lastHours', { count: runtime.hours })}
                    context={
                        runtime.isSampled ? t('runtime.sampled') : undefined
                    }
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.errors')}
                    value={count(requests.errors)}
                    headline={t('runtime.errorRate', {
                        rate: errorRate.toFixed(errorRate < 1 ? 2 : 1),
                    })}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.averageResponse')}
                    value={ms(requests.averageMs)}
                    headline={t('runtime.allRoutes')}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.slowestResponse')}
                    value={ms(requests.maxMs)}
                    headline={t('runtime.allRoutes')}
                />
            </section>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle>{t('runtime.requestsPerHour')}</CardTitle>
                    <CardDescription>
                        {t('runtime.requestsPerHourDescription')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="h-56">
                    <StackedBarChart
                        data={requests.hourly.map((point) => ({
                            label: new Date(point.hour).toLocaleTimeString(
                                undefined,
                                { hour: '2-digit', minute: '2-digit' },
                            ),
                            ok: point.ok,
                            errors: point.errors,
                        }))}
                        series={[
                            {
                                key: 'ok',
                                label: t('runtime.successful'),
                                color: 'var(--runtime-chart-ok)',
                            },
                            {
                                key: 'errors',
                                label: t('runtime.serverErrors'),
                                color: 'var(--runtime-chart-error)',
                            },
                        ]}
                        label={t('runtime.chartLabel', {
                            count: requests.count,
                            errors: requests.errors,
                        })}
                    />
                </CardContent>
            </Card>

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <RuntimeTable
                    className="xl:col-span-7"
                    title={t('runtime.routes')}
                    rows={requests.routes}
                    first={(row) => (
                        <>
                            <div className="truncate font-medium">
                                {String(row.route)}
                            </div>
                            <div className="font-mono text-xs text-muted-foreground">
                                {String(row.method)}
                            </div>
                        </>
                    )}
                    count={count}
                    ms={ms}
                    showErrors
                />
                <RuntimeTable
                    className="xl:col-span-5"
                    title={t('runtime.outgoingHttp')}
                    rows={runtime.outgoingHttp}
                    first={(row) => (
                        <div className="truncate font-mono text-xs">
                            {String(row.host)}
                        </div>
                    )}
                    count={count}
                    ms={ms}
                    showErrors
                />
            </section>

            <RuntimeTable
                title={t('runtime.slowQueries')}
                rows={runtime.slowQueries}
                first={(row) => (
                    <>
                        <div className="line-clamp-2 font-mono text-xs break-all whitespace-normal">
                            {String(row.sql)}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {String(row.connection)}
                        </div>
                    </>
                )}
                count={count}
                ms={ms}
            />

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <RuntimeTable
                    className="xl:col-span-6"
                    title={t('runtime.jobs')}
                    rows={runtime.jobs}
                    first={(row) => (
                        <>
                            <div className="truncate font-mono text-xs">
                                {String(row.job)}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {String(row.queue)}
                            </div>
                        </>
                    )}
                    count={count}
                    ms={ms}
                    outcomes={['processed', 'failed']}
                />
                <RuntimeTable
                    className="xl:col-span-6"
                    title={t('runtime.scheduledTasks')}
                    rows={runtime.scheduledTasks}
                    first={(row) => (
                        <div className="truncate font-mono text-xs">
                            {String(row.task)}
                        </div>
                    )}
                    count={count}
                    ms={ms}
                    outcomes={['finished', 'failed', 'skipped']}
                />
            </section>
        </div>
    );
}

/**
 * One runtime table in a card: the series, its count (and errors or outcomes), average and
 * slowest duration.
 */
function RuntimeTable({
    title,
    rows,
    first,
    count,
    ms,
    showErrors = false,
    outcomes,
    className,
}: {
    title: string;
    rows: RuntimeRow[];
    first: (row: RuntimeRow) => ReactNode;
    count: (value: number) => string;
    ms: (value: number | null) => string;
    showErrors?: boolean;
    outcomes?: string[];
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('runtime.noData')}
                    </p>
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('runtime.name')}</TableHead>
                                    {outcomes ? (
                                        outcomes.map((outcome) => (
                                            <TableHead
                                                key={outcome}
                                                className="text-right"
                                            >
                                                {t(
                                                    `runtime.outcome.${outcome}`,
                                                )}
                                            </TableHead>
                                        ))
                                    ) : (
                                        <TableHead className="text-right">
                                            {t('runtime.count')}
                                        </TableHead>
                                    )}
                                    {showErrors ? (
                                        <TableHead className="text-right">
                                            {t('runtime.serverErrors')}
                                        </TableHead>
                                    ) : null}
                                    <TableHead className="text-right">
                                        {t('runtime.average')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('runtime.max')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, index) => (
                                    <TableRow key={index}>
                                        <TableCell className="max-w-[28rem] min-w-0">
                                            {first(row)}
                                        </TableCell>
                                        {outcomes ? (
                                            outcomes.map((outcome) => (
                                                <TableCell
                                                    key={outcome}
                                                    className={cn(
                                                        'text-right tabular-nums',
                                                        outcome === 'failed' &&
                                                            (row.outcomes[
                                                                outcome
                                                            ] ?? 0) > 0 &&
                                                            'text-destructive',
                                                    )}
                                                >
                                                    {count(
                                                        row.outcomes[outcome] ??
                                                            0,
                                                    )}
                                                </TableCell>
                                            ))
                                        ) : (
                                            <TableCell className="text-right tabular-nums">
                                                {count(row.count)}
                                            </TableCell>
                                        )}
                                        {showErrors ? (
                                            <TableCell
                                                className={cn(
                                                    'text-right tabular-nums',
                                                    row.errors > 0 &&
                                                        'text-destructive',
                                                )}
                                            >
                                                {count(row.errors)}
                                            </TableCell>
                                        ) : null}
                                        <TableCell className="text-right tabular-nums">
                                            {ms(row.averageMs)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {ms(row.maxMs)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
