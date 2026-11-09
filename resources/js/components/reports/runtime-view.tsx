import { Gauge, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import {
    StackedBarChart,
    TimeLineChart,
} from '@/components/reports/severity-chart';
import type {
    ChartMarker,
    ChartZoomHandler,
} from '@/components/reports/severity-chart';
import {
    DEFAULT_TIME_RANGE,
    useRangeTexts,
    useZoomToRange,
    ChartRowsLink,
    deployMarkers,
} from '@/components/reports/time-range-picker';
import { StatCard } from '@/components/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import type {
    RuntimeAuthRow,
    RuntimeBillingRow,
    RuntimeCountRow,
    RuntimeOctaneRow,
    RuntimePennantRow,
    RuntimeReverbChannels,
    RuntimeReverbRow,
    RuntimeScoutRow,
    RuntimeRow,
    RuntimeServer,
    RuntimeSummary,
} from '@/types';

const RUNTIME_GUIDE_URL =
    'https://github.com/qiick-io/excubia-core/blob/main/docs/guides/runtime-monitoring.md';

/** Log levels that count as errors, as in ProjectRuntimeService. */
/** Anchors of the tables behind the charts (#249). */
const ROUTES_ID = 'runtime-routes';
const LOG_LEVELS_ID = 'runtime-log-levels';
const JOBS_ID = 'runtime-jobs';

const ERROR_LOG_LEVELS = ['emergency', 'alert', 'critical', 'error'];

/** One column of a runtime card: a header and a right-aligned numeric cell. */
type Column<Row> = {
    label: string;
    cell: (row: Row) => ReactNode;
    /** Marks the cell as a problem (failures, misses) with the destructive text colour. */
    isProblem?: (row: Row) => boolean;
};

type Formatters = {
    count: (value: number) => string;
    ms: (value: number | null) => string;
    number: (value: number | null, digits?: number) => string;
};

/**
 * The runtime of a project over a time range (#24, #107, #113), Pulse-style: key numbers,
 * requests per chart bucket split into 1/2/3xx, 4xx and 5xx (#251) next to the average and p95
 * duration per bucket (#246), then one card per runtime type. A card without
 * data names the `runtime.*` key of excubia.json that turns it on. Estimated counts carry a `~`
 * when the project samples. Deploys of the range (#106) are dashed markers on the charts.
 */
export function RuntimeView({
    runtime,
    slug,
}: {
    runtime: RuntimeSummary | null;
    slug: string;
}) {
    const { t } = useTranslation();

    const rangeTexts = useRangeTexts(runtime?.range ?? DEFAULT_TIME_RANGE);
    const zoom = useZoomToRange(slug, 'runtime', ROUTES_ID);
    const logZoom = useZoomToRange(slug, 'runtime', LOG_LEVELS_ID);

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
    const format: Formatters = {
        count: (value) => `${approx}${value.toLocaleString()}`,
        ms: (value) =>
            value === null ? '—' : `${Math.round(value).toLocaleString()} ms`,
        number: (value, digits = 1) =>
            value === null
                ? '—'
                : value.toLocaleString(undefined, {
                      maximumFractionDigits: digits,
                  }),
    };
    const { requests } = runtime;
    const errorRate =
        requests.count > 0 ? (requests.errors / requests.count) * 100 : 0;
    const { bucketText } = rangeTexts;
    const range = { bucket: rangeTexts.bucket, during: rangeTexts.during };
    const statusTotals = requests.timeline.reduce(
        (totals, point) => ({
            ok: totals.ok + point.ok,
            clientErrors: totals.clientErrors + point.clientErrors,
            serverErrors: totals.serverErrors + point.serverErrors,
        }),
        { ok: 0, clientErrors: 0, serverErrors: 0 },
    );
    const markers = deployMarkers(runtime.deploys, t('runtime.deploy'));
    const deploysLabel =
        markers.length > 0
            ? ` ${t('runtime.deploysLabel', { count: runtime.deploys.length, releases: markers.map((marker) => `${marker.name} (${bucketText(marker.at)})`).join(', ') })}`
            : '';

    return (
        <div className="flex flex-col gap-4">
            {runtime.skippedAggregates > 0 ? (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        {t('runtime.skipped', {
                            count: runtime.skippedAggregates,
                        })}
                    </AlertDescription>
                </Alert>
            ) : null}

            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.requests')}
                    value={format.count(requests.count)}
                    headline={rangeTexts.headline}
                    context={[
                        `${t('runtime.statusOk')} ${format.count(statusTotals.ok)} · ${t('runtime.statusClientErrors')} ${format.count(statusTotals.clientErrors)} · ${t('runtime.statusServerErrors')} ${format.count(statusTotals.serverErrors)}`,
                        runtime.isSampled ? t('runtime.sampled') : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.errors')}
                    value={format.count(requests.errors)}
                    headline={t('runtime.errorRate', {
                        rate: errorRate.toFixed(errorRate < 1 ? 2 : 1),
                    })}
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.averageResponse')}
                    value={format.ms(requests.averageMs)}
                    headline={t('runtime.allRoutes')}
                    context={
                        requests.p50 === null
                            ? undefined
                            : `p50 ${format.ms(requests.p50)} · p95 ${format.ms(requests.p95)} · p99 ${format.ms(requests.p99)}`
                    }
                />
                <StatCard
                    className="xl:col-span-3"
                    label={t('runtime.slowestResponse')}
                    value={format.ms(requests.maxMs)}
                    headline={t('runtime.allRoutes')}
                />
            </section>

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle>{t('runtime.requestsOverTime')}</CardTitle>
                        <ChartRowsLink
                            rowsId={ROUTES_ID}
                            label={t('charts.viewRoutes')}
                        />
                        <CardDescription>
                            {t('runtime.requestsOverTimeDescription', range)}
                            {markers.length > 0
                                ? ` ${t('runtime.deployMarkersHint')}`
                                : null}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="h-56">
                        <StackedBarChart
                            data={requests.timeline.map((point) => ({
                                label: point.at,
                                ok: point.ok,
                                clientErrors: point.clientErrors,
                                serverErrors: point.serverErrors,
                            }))}
                            formatLabel={bucketText}
                            markers={markers}
                            onZoom={zoom}
                            totalLabel={t('runtime.total')}
                            series={[
                                {
                                    key: 'ok',
                                    label: t('runtime.statusOk'),
                                    color: 'var(--runtime-chart-ok)',
                                },
                                {
                                    key: 'clientErrors',
                                    label: t('runtime.statusClientErrors'),
                                    color: 'var(--runtime-chart-client-error)',
                                },
                                {
                                    key: 'serverErrors',
                                    label: t('runtime.statusServerErrors'),
                                    color: 'var(--runtime-chart-error)',
                                },
                            ]}
                            label={
                                t('runtime.statusChartLabel', {
                                    count: requests.count,
                                    ok: statusTotals.ok,
                                    clientErrors: statusTotals.clientErrors,
                                    serverErrors: statusTotals.serverErrors,
                                    ...range,
                                }) + deploysLabel
                            }
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle>{t('runtime.duration')}</CardTitle>
                        <ChartRowsLink
                            rowsId={ROUTES_ID}
                            label={t('charts.viewRoutes')}
                        />
                        <CardDescription>
                            {t('runtime.durationSummary', {
                                average: format.ms(requests.averageMs),
                                p95: format.ms(requests.p95),
                                max: format.ms(requests.maxMs),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="h-56">
                        <TimeLineChart
                            data={requests.timeline.map((point) => ({
                                label: point.at,
                                averageMs: point.averageMs,
                                p95Ms: point.p95Ms,
                            }))}
                            formatLabel={bucketText}
                            formatValue={(value) => format.ms(value)}
                            markers={markers}
                            onZoom={zoom}
                            series={[
                                {
                                    key: 'averageMs',
                                    label: t('runtime.average'),
                                    color: 'var(--runtime-chart-ok)',
                                },
                                {
                                    key: 'p95Ms',
                                    label: 'p95',
                                    color: 'var(--runtime-chart-p95)',
                                },
                            ]}
                            label={
                                t('runtime.durationChartLabel', {
                                    average: format.ms(requests.averageMs),
                                    p95: format.ms(requests.p95),
                                    max: format.ms(requests.maxMs),
                                    ...range,
                                }) + deploysLabel
                            }
                        />
                    </CardContent>
                </Card>
            </section>

            {/* Timed tables (count, errors, average, p50 to p99, max) need the full width: side
                by side, their percentiles scroll out of sight even at 1440px (#194). */}
            <section className="grid grid-cols-1 gap-4">
                <RuntimeCard
                    id={ROUTES_ID}
                    title={t('runtime.routes')}
                    configKey="runtime.requests"
                    rows={requests.routes}
                    name={(row) => (
                        <Name
                            primary={String(row.route)}
                            secondary={String(row.method)}
                            isSecondaryMono
                        />
                    )}
                    columns={timedColumns(requests.routes, format, t, {
                        errors: t('runtime.serverErrors'),
                    })}
                />
                <RuntimeCard
                    title={t('runtime.outgoingHttp')}
                    configKey="runtime.outgoingHttp"
                    rows={runtime.outgoingHttp}
                    name={(row) => <Name primary={String(row.host)} isMono />}
                    columns={timedColumns(runtime.outgoingHttp, format, t, {
                        errors: t('runtime.serverErrors'),
                    })}
                />
            </section>

            <RuntimeCard
                title={t('runtime.slowQueries')}
                configKey="runtime.slowQueries"
                rows={runtime.slowQueries}
                name={(row) => (
                    <Name
                        primary={String(row.sql)}
                        secondary={String(row.connection)}
                        isMono
                        isWrapped
                    />
                )}
                columns={timedColumns(runtime.slowQueries, format, t)}
            />

            <RuntimeCard
                title={t('runtime.duplicateQueries')}
                description={t('runtime.duplicateQueriesDescription')}
                configKey="runtime.duplicateQueries"
                rows={runtime.duplicateQueries}
                name={(row) => (
                    <Name
                        primary={String(row.sql)}
                        secondary={`${t(`runtime.origin.${String(row.origin)}`)} · ${String(row.name)} · ${String(row.connection)}`}
                        isMono
                        isWrapped
                    />
                )}
                columns={[
                    {
                        label: t('runtime.occurrences'),
                        cell: (row) => format.count(row.count),
                    },
                    {
                        label: t('runtime.averageRepeats'),
                        cell: (row) =>
                            format.number(row.averageRepeats as number | null),
                    },
                    {
                        label: t('runtime.maxRepeats'),
                        cell: (row) =>
                            format.number(row.maxRepeats as number | null),
                    },
                ]}
            />

            <section className="grid grid-cols-1 gap-4">
                <RuntimeCard
                    id={JOBS_ID}
                    title={t('runtime.jobs')}
                    configKey="runtime.jobs"
                    rows={runtime.jobs}
                    name={(row) => (
                        <Name
                            primary={String(row.job)}
                            secondary={String(row.queue)}
                            isMono
                        />
                    )}
                    columns={timedColumns(runtime.jobs, format, t, {
                        outcomes: ['processed', 'failed'],
                    })}
                />
                <RuntimeCard
                    title={t('runtime.scheduledTasks')}
                    configKey="runtime.scheduler"
                    rows={runtime.scheduledTasks}
                    name={(row) => <Name primary={String(row.task)} isMono />}
                    columns={timedColumns(runtime.scheduledTasks, format, t, {
                        outcomes: ['finished', 'failed', 'skipped'],
                    })}
                />
            </section>

            <section className="grid grid-cols-1 gap-4">
                <RuntimeCard
                    title={t('runtime.queueWait')}
                    description={t('runtime.queueWaitDescription')}
                    configKey="runtime.queueWait"
                    rows={runtime.queueWait}
                    name={(row) => (
                        <Name
                            primary={String(row.queue)}
                            secondary={String(row.job)}
                            isSecondaryMono
                        />
                    )}
                    columns={timedColumns(runtime.queueWait, format, t)}
                />
                <RuntimeCard
                    title={t('runtime.commands')}
                    configKey="runtime.commands"
                    rows={runtime.commands}
                    name={(row) => (
                        <Name primary={String(row.command)} isMono />
                    )}
                    columns={timedColumns(runtime.commands, format, t, {
                        errors: t('runtime.failed'),
                    })}
                />
            </section>

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <RuntimeCard
                    className="xl:col-span-6"
                    title={t('runtime.requestMemory')}
                    configKey="runtime.requestMemory"
                    rows={runtime.requestMemory}
                    name={(row) => (
                        <Name
                            primary={String(row.route)}
                            secondary={String(row.method)}
                            isSecondaryMono
                        />
                    )}
                    columns={memoryColumns(format, t)}
                />
                <RuntimeCard
                    className="xl:col-span-6"
                    title={t('runtime.jobMemory')}
                    configKey="runtime.jobMemory"
                    rows={runtime.jobMemory}
                    name={(row) => (
                        <Name
                            primary={String(row.job)}
                            secondary={String(row.queue)}
                            isMono
                        />
                    )}
                    columns={memoryColumns(format, t)}
                />
            </section>

            <RuntimeCard
                title={t('runtime.cache')}
                description={
                    runtime.cache.hitRate === null
                        ? undefined
                        : t('runtime.cacheDescription', {
                              rate: format.number(runtime.cache.hitRate),
                              hits: format.count(runtime.cache.hits),
                              misses: format.count(runtime.cache.misses),
                          })
                }
                configKey="runtime.cache"
                rows={runtime.cache.rows}
                name={(row) => (
                    <Name
                        primary={String(row.keyPrefix)}
                        secondary={String(row.store)}
                        isMono
                    />
                )}
                columns={[
                    {
                        label: t('runtime.hitRate'),
                        cell: (row) =>
                            row.hitRate === null
                                ? '—'
                                : `${format.number(row.hitRate as number)}%`,
                    },
                    {
                        label: t('runtime.hits'),
                        cell: (row) => format.count(row.hits as number),
                    },
                    {
                        label: t('runtime.misses'),
                        cell: (row) => format.count(row.misses as number),
                    },
                    {
                        label: t('runtime.writes'),
                        cell: (row) => format.count(row.writes as number),
                    },
                    {
                        label: t('runtime.deletes'),
                        cell: (row) => format.count(row.deletes as number),
                    },
                ]}
            />

            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <RuntimeCard
                    className="xl:col-span-6"
                    title={t('runtime.mail')}
                    configKey="runtime.mail"
                    rows={runtime.mail}
                    name={(row) => (
                        <Name
                            primary={String(row.mailable)}
                            secondary={String(row.mailer)}
                            isMono
                        />
                    )}
                    columns={sentColumns(format, t)}
                />
                <RuntimeCard
                    className="xl:col-span-6"
                    title={t('runtime.notifications')}
                    configKey="runtime.notifications"
                    rows={runtime.notifications}
                    name={(row) => (
                        <Name
                            primary={String(row.notification)}
                            secondary={String(row.channel)}
                            isMono
                        />
                    )}
                    columns={sentColumns(format, t)}
                />
            </section>

            <LogLevelsCard
                logLevels={runtime.logLevels}
                format={format}
                bucketText={bucketText}
                markers={markers}
                range={range}
                onZoom={logZoom}
            />

            <RuntimeCard
                title={t('runtime.deprecations')}
                description={t('runtime.deprecationsDescription')}
                configKey="runtime.deprecations"
                rows={runtime.deprecations}
                name={(row) => (
                    <Name
                        primary={String(row.message)}
                        secondary={`${String(row.source)} · PHP ${String(row.phpVersion)}`}
                        isWrapped
                    />
                )}
                columns={[
                    {
                        label: t('runtime.count'),
                        cell: (row) => format.count(row.count),
                    },
                ]}
            />

            <ServersCard servers={runtime.servers} format={format} />

            <OctaneCard octane={runtime.octane} format={format} />

            <ReverbCard reverb={runtime.reverb} format={format} />

            <BillingCard billing={runtime.billing} format={format} />

            <AuthCard auth={runtime.auth} format={format} />

            <ScoutCard scout={runtime.scout} format={format} />

            <PennantCard pennant={runtime.pennant} format={format} />
        </div>
    );
}

/**
 * Octane workers (#168) per server and worker type: requests, tasks and ticks, worker starts
 * and stops, errors, memory after a request and its growth since a worker's first request
 * (a possible leak above the threshold, named in a badge), and the requests a worker handled
 * before it stopped against `octane.max_requests` from the laravel-octane-config check.
 */
function OctaneCard({
    octane,
    format,
}: {
    octane: RuntimeSummary['octane'];
    format: Formatters;
}) {
    const { t } = useTranslation();
    const maxRequests = octane.config
        ? octane.config.maxRequests === null
            ? ` ${t('runtime.octaneMaxRequestsUnset')}`
            : ` ${t('runtime.octaneMaxRequests', { count: octane.config.maxRequests })}`
        : '';

    return (
        <RuntimeCard<RuntimeOctaneRow>
            title={t('runtime.octane')}
            description={`${t('runtime.octaneDescription', { threshold: octane.leakGrowthMb })}${maxRequests}`}
            configKey="runtime.octane"
            rows={octane.rows}
            name={(row) => (
                <div className="flex items-center gap-2">
                    <div className="min-w-0">
                        <Name
                            primary={row.server}
                            secondary={`${row.octaneServer} · ${row.workerType}`}
                            isMono
                        />
                    </div>
                    {row.isPossibleLeak ? (
                        <Badge variant="warning">
                            {t('runtime.possibleLeak')}
                        </Badge>
                    ) : null}
                </div>
            )}
            columns={[
                {
                    label: t('runtime.requests'),
                    cell: (row) => format.count(row.requests),
                },
                {
                    label: t('runtime.tasksTicks'),
                    cell: (row) =>
                        `${format.count(row.tasks)} / ${format.count(row.ticks)}`,
                },
                {
                    label: t('runtime.workerStartsStops'),
                    cell: (row) =>
                        `${format.count(row.workerStarts)} / ${format.count(row.workerStops)}`,
                },
                {
                    label: t('runtime.workerErrors'),
                    cell: (row) => format.count(row.errors),
                    isProblem: (row) => row.errors > 0,
                },
                {
                    label: t('runtime.peakMemory'),
                    cell: (row) => `${format.number(row.maxMb)} MB`,
                },
                {
                    label: t('runtime.memoryGrowth'),
                    cell: (row) => `${format.number(row.maxGrowthMb)} MB`,
                    isProblem: (row) => row.isPossibleLeak,
                },
                {
                    label: t('runtime.requestsAtStop'),
                    cell: (row) =>
                        `${format.number(row.averageRequestsAtStop, 0)} / ${format.number(row.maxRequestsAtStop, 0)}`,
                },
            ]}
        />
    );
}

/**
 * Reverb servers (#175) per server and app: messages in and out, connections opened, rejected
 * and pruned, errors, channels created and removed by type, and the peak of connections. The
 * description adds the reachability and connect time from the laravel-reverb check.
 */
function ReverbCard({
    reverb,
    format,
}: {
    reverb: RuntimeSummary['reverb'];
    format: Formatters;
}) {
    const { t } = useTranslation();
    const reachability = reverb.check
        ? reverb.check.isReachable
            ? ` ${t('runtime.reverbReachable', { latency: format.number(reverb.check.latencyMs) })}`
            : ` ${t('runtime.reverbUnreachable')}`
        : '';
    const channels = (counts: RuntimeReverbChannels) =>
        `${format.count(counts.public)} / ${format.count(counts.private)} / ${format.count(counts.presence)}`;

    return (
        <RuntimeCard<RuntimeReverbRow>
            title={t('runtime.reverb')}
            description={`${t('runtime.reverbDescription')}${reachability}`}
            configKey="runtime.reverb"
            rows={reverb.rows}
            name={(row) => (
                <Name primary={row.appId} secondary={row.server} isMono />
            )}
            columns={[
                {
                    label: t('runtime.messagesInOut'),
                    cell: (row) =>
                        `${format.count(row.messagesReceived)} / ${format.count(row.messagesSent)}`,
                },
                {
                    label: t('runtime.connectionsOpened'),
                    cell: (row) => format.count(row.connectionsOpened),
                },
                {
                    label: t('runtime.connectionsRejected'),
                    cell: (row) => format.count(row.connectionsRejected),
                    isProblem: (row) => row.connectionsRejected > 0,
                },
                {
                    label: t('runtime.connectionsPruned'),
                    cell: (row) => format.count(row.connectionsPruned),
                },
                {
                    label: t('runtime.reverbErrors'),
                    cell: (row) => format.count(row.errors),
                    isProblem: (row) => row.errors > 0,
                },
                {
                    label: t('runtime.channelsCreated'),
                    cell: (row) => channels(row.channelsCreated),
                },
                {
                    label: t('runtime.channelsRemoved'),
                    cell: (row) => channels(row.channelsRemoved),
                },
                {
                    label: t('runtime.peakConnections'),
                    cell: (row) => format.number(row.peakConnections, 0),
                },
            ]}
        />
    );
}

/**
 * Laravel Cashier webhooks (#178) per provider and event type: received, handled, failed with
 * the failure rate, p95 and slowest handling time, and the payment signals, whose totals over
 * the range lead the card as badges (in the warning or destructive tone when not zero).
 */
function BillingCard({
    billing,
    format,
}: {
    billing: RuntimeSummary['billing'];
    format: Formatters;
}) {
    const { t } = useTranslation();
    const signals = [
        { key: 'paymentFailed', variant: 'destructive' },
        { key: 'paymentActionRequired', variant: 'warning' },
        { key: 'subscriptionCanceled', variant: 'warning' },
    ] as const;

    return (
        <RuntimeCard<RuntimeBillingRow>
            title={t('runtime.billing')}
            description={t('runtime.billingDescription')}
            configKey="runtime.billing"
            rows={billing.rows}
            summary={
                <div
                    className="flex flex-wrap gap-2"
                    aria-label={t('runtime.billingTotals')}
                >
                    {signals.map((signal) => (
                        <Badge
                            key={signal.key}
                            variant={
                                billing.totals[signal.key] > 0
                                    ? signal.variant
                                    : 'muted'
                            }
                        >
                            {t(`runtime.${signal.key}`)}{' '}
                            {format.count(billing.totals[signal.key])}
                        </Badge>
                    ))}
                </div>
            }
            name={(row) => (
                <Name primary={row.eventType} secondary={row.provider} isMono />
            )}
            columns={[
                {
                    label: t('runtime.received'),
                    cell: (row) => format.count(row.count),
                },
                {
                    label: t('runtime.handled'),
                    cell: (row) => format.count(row.handled),
                },
                {
                    label: t('runtime.failedRate'),
                    cell: (row) =>
                        `${format.count(row.errors)} (${format.number(row.errorRate)}%)`,
                    isProblem: (row) => row.errors > 0,
                },
                { label: 'p95', cell: (row) => format.ms(row.p95) },
                {
                    label: t('runtime.max'),
                    cell: (row) => format.ms(row.maxMs),
                },
                ...signals.map((signal) => ({
                    label: t(`runtime.${signal.key}`),
                    cell: (row: RuntimeBillingRow) =>
                        format.count(row[signal.key]),
                    isProblem: (row: RuntimeBillingRow) =>
                        signal.key === 'paymentFailed' && row[signal.key] > 0,
                })),
            ]}
        />
    );
}

/**
 * Laravel authentication (#180) per guard: successful logins, failed logins with the failure
 * rate, lockouts and Sanctum token authentications.
 */
function AuthCard({
    auth,
    format,
}: {
    auth: RuntimeSummary['auth'];
    format: Formatters;
}) {
    const { t } = useTranslation();

    return (
        <RuntimeCard<RuntimeAuthRow>
            title={t('runtime.auth')}
            description={t('runtime.authDescription')}
            configKey="runtime.auth"
            rows={auth}
            name={(row) => <Name primary={row.guard} isMono />}
            columns={[
                {
                    label: t('runtime.logins'),
                    cell: (row) => format.count(row.logins),
                },
                {
                    label: t('runtime.failedRate'),
                    cell: (row) =>
                        row.failureRate === null
                            ? format.count(row.failed)
                            : `${format.count(row.failed)} (${format.number(row.failureRate)}%)`,
                    isProblem: (row) => row.failed > 0,
                },
                {
                    label: t('runtime.lockouts'),
                    cell: (row) => format.count(row.lockouts),
                    isProblem: (row) => row.lockouts > 0,
                },
                {
                    label: t('runtime.tokenAuthentications'),
                    cell: (row) => format.count(row.tokenAuthentications),
                },
            ]}
        />
    );
}

/**
 * Laravel Scout (#184) per model, engine and operation: index and remove jobs with failures
 * and the failure rate, records synced, p95 and slowest job; searches show only their count
 * (core sends no failures, records or durations for them).
 */
function ScoutCard({
    scout,
    format,
}: {
    scout: RuntimeSummary['scout'];
    format: Formatters;
}) {
    const { t } = useTranslation();
    const isSearch = (row: RuntimeScoutRow) => row.operation === 'search';

    return (
        <RuntimeCard<RuntimeScoutRow>
            title={t('runtime.scout')}
            description={t('runtime.scoutDescription')}
            configKey="runtime.scout"
            rows={scout}
            name={(row) => (
                <Name
                    primary={row.model}
                    secondary={`${row.engine} · ${row.operation}`}
                    isMono
                    isSecondaryMono
                />
            )}
            columns={[
                {
                    label: t('runtime.count'),
                    cell: (row) => format.count(row.count),
                },
                {
                    label: t('runtime.failedRate'),
                    cell: (row) =>
                        isSearch(row)
                            ? '—'
                            : `${format.count(row.errors)} (${format.number(row.errorRate)}%)`,
                    isProblem: (row) => row.errors > 0,
                },
                {
                    label: t('runtime.scoutRecords'),
                    cell: (row) =>
                        isSearch(row) ? '—' : format.count(row.records),
                },
                { label: 'p95', cell: (row) => format.ms(row.p95) },
                {
                    label: t('runtime.max'),
                    cell: (row) => format.ms(row.maxMs),
                },
            ]}
        />
    );
}

/**
 * Laravel Pennant (#189) per feature: checks with the active and inactive split and the active
 * share (rollout progress), first resolutions and checks of an undefined feature (a removed
 * flag still checked, or a typo). Hashed names carry a badge. The stale flags (always the same
 * value on each day of the stale window) and the undefined features lead the card as badges;
 * a note says when the runtime retention shortens the stale window below the setting.
 */
function PennantCard({
    pennant,
    format,
}: {
    pennant: RuntimeSummary['pennant'];
    format: Formatters;
}) {
    const { t } = useTranslation();
    const staleValues = new Map(
        pennant.stale.map((stale) => [stale.feature, stale.value]),
    );
    const undefinedFeatures = pennant.rows.filter((row) => row.unknown > 0);

    return (
        <RuntimeCard<RuntimePennantRow>
            title={t('runtime.pennant')}
            description={t('runtime.pennantDescription')}
            configKey="runtime.pennant"
            rows={pennant.rows}
            summary={
                <div className="flex flex-col gap-2">
                    <p className="text-sm text-muted-foreground">
                        {t('runtime.pennantStaleRule', {
                            count: pennant.staleWindowDays,
                        })}
                        {pennant.staleWindowDays < pennant.staleAfterDays
                            ? ` ${t('runtime.pennantStaleRetention', {
                                  setting: pennant.staleAfterDays,
                                  window: pennant.staleWindowDays,
                              })}`
                            : null}
                    </p>
                    <div
                        className="flex flex-wrap gap-2"
                        aria-label={t('runtime.pennantStaleFlags')}
                    >
                        {pennant.stale.length === 0 ? (
                            <Badge variant="muted">
                                {t('runtime.pennantNoStaleFlags')}
                            </Badge>
                        ) : (
                            pennant.stale.map((stale) => (
                                <Badge key={stale.feature} variant="warning">
                                    <span className="font-mono">
                                        {stale.feature}
                                    </span>{' '}
                                    {t(
                                        stale.value === 'active'
                                            ? 'runtime.pennantAlwaysActive'
                                            : 'runtime.pennantAlwaysInactive',
                                    )}
                                </Badge>
                            ))
                        )}
                    </div>
                    {undefinedFeatures.length > 0 ? (
                        <div
                            className="flex flex-wrap gap-2"
                            aria-label={t('runtime.pennantUndefinedFeatures')}
                        >
                            {undefinedFeatures.map((row) => (
                                <Badge key={row.feature} variant="destructive">
                                    <span className="font-mono">
                                        {row.feature}
                                    </span>{' '}
                                    {t('runtime.pennantUndefinedChecks', {
                                        count: row.unknown,
                                    })}
                                </Badge>
                            ))}
                        </div>
                    ) : null}
                </div>
            }
            name={(row) => {
                const staleValue = staleValues.get(row.feature);

                return (
                    <div className="flex items-center gap-2">
                        <div className="min-w-0">
                            <Name primary={row.feature} isMono />
                        </div>
                        {row.isHashed ? (
                            <Badge
                                variant="muted"
                                title={t('runtime.pennantHashedHint')}
                            >
                                {t('runtime.pennantHashed')}
                            </Badge>
                        ) : null}
                        {staleValue ? (
                            <Badge variant="warning">
                                {t(
                                    staleValue === 'active'
                                        ? 'runtime.pennantAlwaysActive'
                                        : 'runtime.pennantAlwaysInactive',
                                )}
                            </Badge>
                        ) : null}
                    </div>
                );
            }}
            columns={[
                {
                    label: t('runtime.pennantChecks'),
                    cell: (row) => format.count(row.checks),
                },
                {
                    label: t('runtime.pennantActiveInactive'),
                    cell: (row) =>
                        `${format.count(row.active)} / ${format.count(row.inactive)}`,
                },
                {
                    label: t('runtime.pennantActiveShare'),
                    cell: (row) =>
                        row.activeShare === null
                            ? '—'
                            : `${format.number(row.activeShare)}%`,
                },
                {
                    label: t('runtime.pennantResolved'),
                    cell: (row) => format.count(row.resolved),
                },
                {
                    label: t('runtime.pennantUnknown'),
                    cell: (row) => format.count(row.unknown),
                    isProblem: (row) => row.unknown > 0,
                },
            ]}
        />
    );
}

/**
 * Count, errors or outcomes, average and slowest duration of timed rows, plus p50, p95 and p99
 * when any row has a histogram.
 */
function timedColumns(
    rows: RuntimeRow[],
    format: Formatters,
    t: (key: string) => string,
    { errors, outcomes }: { errors?: string; outcomes?: string[] } = {},
): Column<RuntimeRow>[] {
    const counts: Column<RuntimeRow>[] = outcomes
        ? outcomes.map((outcome) => ({
              label: t(`runtime.outcome.${outcome}`),
              cell: (row) => format.count(row.outcomes[outcome] ?? 0),
              isProblem: (row) =>
                  outcome === 'failed' && (row.outcomes[outcome] ?? 0) > 0,
          }))
        : [
              {
                  label: t('runtime.count'),
                  cell: (row) => format.count(row.count),
              },
          ];

    if (errors) {
        counts.push({
            label: errors,
            cell: (row) => format.count(row.errors),
            isProblem: (row) => row.errors > 0,
        });
    }

    const percentiles: Column<RuntimeRow>[] = rows.some(
        (row) => row.p50 !== null,
    )
        ? (['p50', 'p95', 'p99'] as const).map((key) => ({
              label: key,
              cell: (row) => format.ms(row[key]),
          }))
        : [];

    return [
        ...counts,
        {
            label: t('runtime.average'),
            cell: (row) => format.ms(row.averageMs),
        },
        ...percentiles,
        { label: t('runtime.max'), cell: (row) => format.ms(row.maxMs) },
    ];
}

/** Runs, average and largest peak memory. */
function memoryColumns(
    format: Formatters,
    t: (key: string) => string,
): Column<RuntimeCountRow>[] {
    return [
        { label: t('runtime.count'), cell: (row) => format.count(row.count) },
        {
            label: t('runtime.averageMemory'),
            cell: (row) =>
                `${format.number(row.averageMb as number | null)} MB`,
        },
        {
            label: t('runtime.peakMemory'),
            cell: (row) => `${format.number(row.maxMb as number | null)} MB`,
        },
    ];
}

/** Sent and failed, for mail and notifications. */
function sentColumns(
    format: Formatters,
    t: (key: string) => string,
): Column<RuntimeCountRow>[] {
    return [
        { label: t('runtime.sent'), cell: (row) => format.count(row.count) },
        {
            label: t('runtime.failed'),
            cell: (row) => format.count(row.errors as number),
            isProblem: (row) => (row.errors as number) > 0,
        },
    ];
}

/**
 * The name cell of a runtime row: the series name and, muted below it, what qualifies it.
 */
function Name({
    primary,
    secondary,
    isMono = false,
    isSecondaryMono = false,
    isWrapped = false,
}: {
    primary: string;
    secondary?: string;
    isMono?: boolean;
    isSecondaryMono?: boolean;
    isWrapped?: boolean;
}) {
    return (
        <>
            <div
                className={cn(
                    isMono ? 'font-mono text-xs' : 'font-medium',
                    // A wrapped name (SQL, a deprecation) keeps a readable width on phones,
                    // where the table scrolls instead, and breaks inside a word only when it must.
                    isWrapped
                        ? 'line-clamp-2 min-w-48 wrap-anywhere whitespace-normal'
                        : 'truncate',
                )}
            >
                {primary}
            </div>
            {secondary ? (
                <div
                    className={cn(
                        'truncate text-xs text-muted-foreground',
                        isSecondaryMono && 'font-mono',
                    )}
                >
                    {secondary}
                </div>
            ) : null}
        </>
    );
}

/**
 * What a runtime card shows without data: none in the range, and the excubia.json key
 * that turns the type on.
 */
export function NoData({ configKey }: { configKey: string }) {
    const { t } = useTranslation();

    return (
        <p className="text-sm text-muted-foreground">
            {t('runtime.noData')} {t('runtime.enableHint')}{' '}
            <code className="font-mono text-xs">{configKey}.enabled</code>
        </p>
    );
}

/**
 * One runtime card: a table of series with a name column and numeric columns (after an
 * optional summary), or the empty state naming its `runtime.*` key.
 */
function RuntimeCard<Row extends Record<string, unknown>>({
    id,
    title,
    description,
    configKey,
    rows,
    name,
    columns,
    summary,
    className,
}: {
    /** Anchor of the card, for a chart's View link and bucket click (#249). */
    id?: string;
    title: string;
    description?: string;
    /** Shown above the table when there are rows, e.g. the totals of the card. */
    summary?: ReactNode;
    /** The excubia.json key of the type, e.g. `runtime.cache`. */
    configKey: string;
    rows: Row[];
    name: (row: Row) => ReactNode;
    columns: Column<Row>[];
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card id={id} className={cn('scroll-mt-4', className)}>
            <CardHeader className="pb-3">
                <CardTitle>{title}</CardTitle>
                {description ? (
                    <CardDescription>{description}</CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {rows.length === 0 ? (
                    <NoData configKey={configKey} />
                ) : (
                    <>
                        {summary}
                        <div className="max-h-80 overflow-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('runtime.name')}
                                        </TableHead>
                                        {columns.map((column) => (
                                            <TableHead
                                                key={column.label}
                                                className="text-right"
                                            >
                                                {column.label}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row, index) => (
                                        <TableRow key={index}>
                                            <TableCell className="max-w-[28rem] min-w-0">
                                                {name(row)}
                                            </TableCell>
                                            {columns.map((column) => (
                                                <TableCell
                                                    key={column.label}
                                                    className={cn(
                                                        'text-right tabular-nums',
                                                        column.isProblem?.(
                                                            row,
                                                        ) && 'text-destructive',
                                                    )}
                                                >
                                                    {column.cell(row)}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Log entries: totals per level as badges (error levels in the destructive tone), error-level
 * entries per hour, and the table per level and channel.
 */
function LogLevelsCard({
    logLevels,
    format,
    bucketText,
    markers,
    range,
    onZoom,
}: {
    logLevels: RuntimeSummary['logLevels'];
    format: Formatters;
    bucketText: (hour: string) => string;
    /** The bucket and range phrases of the chart texts. */
    range: { bucket: string; during: string };
    markers: ChartMarker[];
    onZoom: ChartZoomHandler;
}) {
    const { t } = useTranslation();
    const errors = logLevels.timeline.reduce(
        (sum, point) => sum + point.count,
        0,
    );

    return (
        <Card id={LOG_LEVELS_ID} className="scroll-mt-4">
            <CardHeader className="pb-3">
                <CardTitle>{t('runtime.logLevels')}</CardTitle>
                <CardDescription>
                    {t('runtime.logLevelsDescription', range)}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {logLevels.levels.length === 0 ? (
                    <NoData configKey="runtime.logLevels" />
                ) : (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {logLevels.levels.map((level) => (
                                <Badge
                                    key={level.level}
                                    variant={
                                        ERROR_LOG_LEVELS.includes(level.level)
                                            ? 'destructive'
                                            : level.level === 'warning'
                                              ? 'warning'
                                              : 'muted'
                                    }
                                >
                                    {level.level} {format.count(level.count)}
                                </Badge>
                            ))}
                        </div>
                        <div className="h-48">
                            <StackedBarChart
                                onZoom={onZoom}
                                data={logLevels.timeline.map((point) => ({
                                    label: point.at,
                                    errors: point.count,
                                }))}
                                formatLabel={bucketText}
                                markers={markers}
                                series={[
                                    {
                                        key: 'errors',
                                        label: t('runtime.errorLogs'),
                                        color: 'var(--runtime-chart-error)',
                                    },
                                ]}
                                label={t('runtime.logChartLabel', {
                                    count: errors,
                                    ...range,
                                })}
                            />
                        </div>
                        <div className="max-h-80 overflow-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('runtime.level')}
                                        </TableHead>
                                        <TableHead>
                                            {t('runtime.channel')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {t('runtime.count')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {logLevels.rows.map((row, index) => (
                                        <TableRow key={index}>
                                            <TableCell className="font-medium">
                                                {String(row.level)}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {row.channel === null
                                                    ? t(
                                                          'runtime.unknownChannel',
                                                      )
                                                    : (row.channel as string)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {format.count(row.count)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * The latest sample of each server: load against its CPU limit (or cores), memory and disk,
 * and whether it runs in a container. A server name may be a hash: it is shown as is.
 */
function ServersCard({
    servers,
    format,
}: {
    servers: RuntimeServer[];
    format: Formatters;
}) {
    const { t } = useTranslation();
    const used = (
        value: number | null,
        total: number | null,
        unit: string,
    ): string =>
        value === null
            ? '—'
            : total === null || total === 0
              ? `${format.number(value, 0)} ${unit}`
              : `${format.number(value, 0)} / ${format.number(total, 0)} ${unit} (${Math.round((value / total) * 100)}%)`;

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('runtime.servers')}</CardTitle>
                <CardDescription>
                    {t('runtime.serversDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {servers.length === 0 ? (
                    <NoData configKey="runtime.server" />
                ) : (
                    <div className="overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('runtime.server')}</TableHead>
                                    <TableHead className="text-right">
                                        {t('runtime.load')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('runtime.memory')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('runtime.disk')}
                                    </TableHead>
                                    <TableHead className="text-right">
                                        {t('runtime.lastSample')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {servers.map((server) => {
                                    const cpus =
                                        server.cpuLimit ?? server.cpuCores;
                                    const isOverloaded =
                                        cpus !== null &&
                                        server.loadAverage5 !== null &&
                                        server.loadAverage5 > cpus;

                                    return (
                                        <TableRow key={server.server}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <span className="truncate font-mono text-xs">
                                                        {server.server}
                                                    </span>
                                                    {server.isContainer ? (
                                                        <Badge variant="muted">
                                                            {t(
                                                                'runtime.container',
                                                            )}
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'text-right tabular-nums',
                                                    isOverloaded &&
                                                        'text-destructive',
                                                )}
                                            >
                                                <div>
                                                    {[
                                                        server.loadAverage1,
                                                        server.loadAverage5,
                                                        server.loadAverage15,
                                                    ]
                                                        .map((load) =>
                                                            format.number(
                                                                load,
                                                                2,
                                                            ),
                                                        )
                                                        .join(' / ')}
                                                </div>
                                                {cpus !== null ? (
                                                    <div className="text-xs text-muted-foreground">
                                                        {t(
                                                            server.cpuLimit !==
                                                                null
                                                                ? 'runtime.cpuLimit'
                                                                : 'runtime.cpuCores',
                                                            {
                                                                count: cpus,
                                                            },
                                                        )}
                                                    </div>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {used(
                                                    server.memoryUsedMb,
                                                    server.memoryTotalMb,
                                                    'MB',
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {used(
                                                    server.diskUsedGb,
                                                    server.diskTotalGb,
                                                    'GB',
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-xs text-muted-foreground">
                                                {new Date(
                                                    server.lastAt,
                                                ).toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
