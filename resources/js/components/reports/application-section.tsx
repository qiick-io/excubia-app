import { Link } from '@inertiajs/react';
import { ArrowUpRight, Gauge } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { CopySnippet } from '@/components/projects/copy-snippet';
import { RUNTIME_GUIDE_URL } from '@/components/reports/runtime-view';
import { StackedBarChart } from '@/components/reports/severity-chart';
import {
    DEFAULT_TIME_RANGE,
    useRangeTabUrl,
    useRangeTexts,
} from '@/components/reports/time-range-picker';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    ExceptionsOverview,
    RuntimeRow,
    RuntimeSummary,
    TimeRange,
} from '@/types';

/** Rows in each list of the section. */
const LIST_LENGTH = 5;

/** The excubia.json keys that turn on what the section shows: runtime monitoring and exceptions. */
const ENABLE_SNIPPET = `{
  "runtime": { "enabled": true },
  "exceptions": { "enabled": true }
}`;

/** Static classes, so Tailwind sees them: the cards share the row, whatever their number. */
const CARD_SPAN: Record<number, string> = {
    1: 'xl:col-span-12',
    2: 'xl:col-span-6',
    3: 'xl:col-span-4',
};

/** A card of the section: title, description, a "View ↗" link to its tab, then the content. */
function ApplicationCard({
    title,
    description,
    href,
    linkLabel,
    className,
    children,
}: {
    title: string;
    description: string;
    href: string;
    /** Accessible name of the link, e.g. "View exceptions". */
    linkLabel: string;
    className?: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
                <CardAction>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={href} aria-label={linkLabel}>
                            {t('charts.view')}
                            <ArrowUpRight className="size-3.5" />
                        </Link>
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {children}
            </CardContent>
        </Card>
    );
}

/** A short ranked list: a name on the left, its value on the right. */
function RankedList({
    title,
    rows,
}: {
    title: string;
    rows: { key: string; name: ReactNode; value: string }[];
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <h3 className="text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            <ul className="flex flex-col divide-y divide-border text-sm">
                {rows.map((row) => (
                    <li
                        key={row.key}
                        className="flex items-baseline justify-between gap-3 py-1.5"
                    >
                        <span className="min-w-0 truncate">{row.name}</span>
                        <span className="shrink-0 tabular-nums">
                            {row.value}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function routeName(row: RuntimeRow): ReactNode {
    return (
        <>
            <span className="font-mono text-xs text-muted-foreground">
                {String(row.method)}
            </span>{' '}
            {String(row.route)}
        </>
    );
}

/**
 * The overview's Application section (#250), after the problems: exceptions, routes and jobs
 * over the page's range, each in a small card with a "View ↗" link to its tab carrying the
 * range. Exceptions show their occurrences per bucket and the groups seen most; routes the
 * slowest by p95 and the most failing by 5xx; jobs processed against failed per bucket.
 *
 * Only the types with data in the range get a card (#292): the app cannot read the project's
 * excubia.json, so no data is the sign that a type is off. One line names the missing types;
 * with none of them, one empty state replaces the cards with the excubia.json keys to copy.
 */
export function ApplicationSection({
    slug,
    runtime,
    exceptions,
}: {
    slug: string;
    runtime: RuntimeSummary | null;
    exceptions: ExceptionsOverview | null;
}) {
    const { t, i18n } = useTranslation();
    const range: TimeRange = runtime?.range ?? DEFAULT_TIME_RANGE;
    const rangeTexts = useRangeTexts(range);
    const tabUrl = useRangeTabUrl();
    const phrases = { bucket: rangeTexts.bucket, during: rangeTexts.during };

    const occurrences =
        exceptions?.timeline.reduce((sum, point) => sum + point.count, 0) ?? 0;
    const routes = runtime?.requests.routes ?? [];
    const slowest = [...routes]
        .filter((row) => row.p95 !== null)
        .sort((first, second) => (second.p95 ?? 0) - (first.p95 ?? 0))
        .slice(0, LIST_LENGTH);
    const failing = [...routes]
        .filter((row) => row.errors > 0)
        .sort((first, second) => second.errors - first.errors)
        .slice(0, LIST_LENGTH);
    const jobTimeline = runtime?.jobTimeline ?? [];
    const jobTotals = jobTimeline.reduce(
        (totals, point) => ({
            processed: totals.processed + point.processed,
            failed: totals.failed + point.failed,
        }),
        { processed: 0, failed: 0 },
    );
    const ms = (value: number | null): string =>
        value === null ? '—' : `${Math.round(value).toLocaleString()} ms`;
    const [copiedText, copy] = useClipboard();

    const hasExceptions = exceptions !== null && occurrences > 0;
    const hasRoutes = routes.length > 0;
    const hasJobs = jobTotals.processed + jobTotals.failed > 0;
    const collected = [hasExceptions, hasRoutes, hasJobs].filter(Boolean);
    const cardSpan = CARD_SPAN[collected.length];
    const notCollected = [
        hasExceptions ? null : t('application.exceptions'),
        hasRoutes ? null : t('application.routes'),
        hasJobs ? null : t('application.jobs'),
    ].filter((label): label is string => label !== null);

    return (
        <section
            aria-labelledby="application-heading"
            className="flex flex-col gap-3"
        >
            <div className="flex items-baseline justify-between gap-3">
                <h2
                    id="application-heading"
                    className="text-base font-semibold"
                >
                    {t('application.title')}
                </h2>
                {collected.length > 0 ? (
                    <span className="text-sm text-muted-foreground">
                        {rangeTexts.headline}
                    </span>
                ) : null}
            </div>

            {collected.length === 0 ? (
                <PageEmptyState
                    icon={Gauge}
                    title={t('application.offTitle')}
                    description={t('application.offDescription')}
                    action={
                        <div className="w-full text-left">
                            <CopySnippet
                                label="excubia.json"
                                text={ENABLE_SNIPPET}
                                copiedText={copiedText}
                                copy={copy}
                            />
                        </div>
                    }
                    docsHref={RUNTIME_GUIDE_URL}
                    docsLabel={t('runtime.guide')}
                    compact
                />
            ) : (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    {hasExceptions ? (
                        <ApplicationCard
                            className={cardSpan}
                            title={t('application.exceptions')}
                            description={t(
                                'application.exceptionsDescription',
                                {
                                    count: occurrences,
                                    formattedCount:
                                        occurrences.toLocaleString(),
                                    ...phrases,
                                },
                            )}
                            href={tabUrl(slug, 'exceptions')}
                            linkLabel={t('application.viewExceptions')}
                        >
                            <>
                                <div className="h-32">
                                    <StackedBarChart
                                        data={exceptions.timeline.map(
                                            (point) => ({
                                                label: point.at,
                                                count: point.count,
                                            }),
                                        )}
                                        formatLabel={rangeTexts.bucketText}
                                        series={[
                                            {
                                                key: 'count',
                                                label: t(
                                                    'application.occurrences',
                                                ),
                                                color: 'var(--runtime-chart-error)',
                                            },
                                        ]}
                                        label={t(
                                            'application.exceptionsChartLabel',
                                            { count: occurrences, ...phrases },
                                        )}
                                    />
                                </div>
                                <RankedList
                                    title={t('application.topExceptions')}
                                    rows={exceptions.top.map((group) => ({
                                        key: String(group.id),
                                        name: (
                                            <>
                                                <span className="font-medium">
                                                    {group.class
                                                        .split('\\')
                                                        .pop()}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    {group.message}
                                                </span>
                                            </>
                                        ),
                                        value: (
                                            group.range_count ?? 0
                                        ).toLocaleString(),
                                    }))}
                                />
                            </>
                        </ApplicationCard>
                    ) : null}

                    {hasRoutes ? (
                        <ApplicationCard
                            className={cardSpan}
                            title={t('application.routes')}
                            description={t(
                                'application.routesDescription',
                                phrases,
                            )}
                            href={tabUrl(slug, 'runtime', 'runtime-routes')}
                            linkLabel={t('charts.viewRoutes')}
                        >
                            <>
                                <RankedList
                                    title={t('application.slowestRoutes')}
                                    rows={slowest.map((row) => ({
                                        key: `${String(row.method)} ${String(row.route)}`,
                                        name: routeName(row),
                                        value: `p95 ${ms(row.p95)}`,
                                    }))}
                                />
                                {failing.length > 0 ? (
                                    <RankedList
                                        title={t('application.failingRoutes')}
                                        rows={failing.map((row) => ({
                                            key: `${String(row.method)} ${String(row.route)}`,
                                            name: routeName(row),
                                            value: `5xx ${row.errors.toLocaleString()}`,
                                        }))}
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t('application.noFailingRoutes')}
                                    </p>
                                )}
                            </>
                        </ApplicationCard>
                    ) : null}

                    {hasJobs ? (
                        <ApplicationCard
                            className={cardSpan}
                            title={t('application.jobs')}
                            description={t('application.jobsDescription', {
                                processed: jobTotals.processed.toLocaleString(),
                                failed: jobTotals.failed.toLocaleString(),
                                ...phrases,
                            })}
                            href={tabUrl(slug, 'runtime', 'runtime-jobs')}
                            linkLabel={t('application.viewJobs')}
                        >
                            <div className="h-32">
                                <StackedBarChart
                                    data={jobTimeline.map((point) => ({
                                        label: point.at,
                                        processed: point.processed,
                                        failed: point.failed,
                                    }))}
                                    formatLabel={rangeTexts.bucketText}
                                    series={[
                                        {
                                            key: 'processed',
                                            label: t('application.processed'),
                                            color: 'var(--runtime-chart-ok)',
                                        },
                                        {
                                            key: 'failed',
                                            label: t('application.failed'),
                                            color: 'var(--runtime-chart-error)',
                                        },
                                    ]}
                                    label={t('application.jobsChartLabel', {
                                        processed: jobTotals.processed,
                                        failed: jobTotals.failed,
                                        ...phrases,
                                    })}
                                />
                            </div>
                        </ApplicationCard>
                    ) : null}
                </div>
            )}

            {collected.length > 0 && notCollected.length > 0 ? (
                <div className="flex flex-wrap items-center gap-x-3 text-sm text-muted-foreground">
                    <p>
                        {t('application.notCollected', {
                            types: new Intl.ListFormat(i18n.language, {
                                type: 'conjunction',
                            }).format(notCollected),
                        })}
                    </p>
                    <Button
                        variant="link"
                        size="sm"
                        asChild
                        className="px-0 text-muted-foreground"
                    >
                        <a
                            href={RUNTIME_GUIDE_URL}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {t('runtime.guide')}
                            <ArrowUpRight className="size-3.5" />
                        </a>
                    </Button>
                </div>
            ) : null}
        </section>
    );
}
