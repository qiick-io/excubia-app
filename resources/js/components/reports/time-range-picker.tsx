import { router, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { DateRangePicker } from '@/components/admin/date-range-picker';
import type { DateRangeValue } from '@/components/admin/date-range-picker';
import { shortRelease } from '@/components/reports/release-label';
import type { ChartMarker } from '@/components/reports/severity-chart';
import { Button } from '@/components/ui/button';
import { CardAction } from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import projectsRoutes from '@/routes/projects';
import type { RuntimeDeploy, TimeRange, TimeRangeWindow } from '@/types';

type PresetRange = Exclude<TimeRange, 'custom'>;

/** The presets of `TimeRangeEnum`, shortest first. */
const TIME_RANGES: PresetRange[] = ['1h', '6h', '24h', '7d', '14d'];

export const DEFAULT_TIME_RANGE: TimeRange = '24h';

const DAY_SECONDS = 86400;

type RangeView = 'runtime' | 'ai' | 'exceptions' | 'schedule';

/** `YYYY-MM-DDTHH:mm` in local time, the picker's wire format, from an ISO instant. */
function toLocalWire(iso: string): string {
    const date = new Date(iso);
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function visitRange(
    slug: string,
    view: RangeView,
    query: { range?: string; from?: string; to?: string },
    scrollToId?: string,
): void {
    router.get(
        projectsRoutes.view.url({ project: slug, view }, { query }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onSuccess: () => {
                if (scrollToId !== undefined) {
                    document
                        .getElementById(scrollToId)
                        ?.scrollIntoView({ block: 'start' });
                }
            },
        },
    );
}

/**
 * The time range of the runtime, AI, exceptions and schedule tabs (#113, #129): the presets as a
 * segmented control, like the dashboard period, and a custom from–to range with times (#247) on
 * the registry date range picker, bounded by the runtime retention and now. A change reloads the
 * tab with `?range=` (left out for the default) or `?from=&to=` (UTC), so the range survives
 * reloads, links and back; clearing the custom range goes back to the default preset.
 */
export function TimeRangePicker({
    slug,
    view,
    range,
}: {
    slug: string;
    view: RangeView;
    range: TimeRange;
}) {
    const { t } = useTranslation();
    const { rangeWindow } = usePage<{ rangeWindow: TimeRangeWindow }>().props;
    const isCustom = range === 'custom';

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <DateRangePicker
                className="w-auto max-w-80"
                value={
                    isCustom
                        ? {
                              from: toLocalWire(rangeWindow.from),
                              to: toLocalWire(rangeWindow.to),
                          }
                        : { from: '', to: '' }
                }
                onChange={(value: DateRangeValue) =>
                    value.from === '' || value.to === ''
                        ? visitRange(slug, view, {})
                        : visitRange(slug, view, {
                              from: new Date(value.from).toISOString(),
                              to: new Date(value.to).toISOString(),
                          })
                }
                placeholder={t('timeRange.custom.placeholder')}
                clearLabel={t('timeRange.custom.clear')}
                aria-label={t('timeRange.custom.label')}
                withTime
                min={new Date(rangeWindow.earliest)}
                max={new Date()}
            />
            <ToggleGroup
                type="single"
                variant="outline"
                value={isCustom ? '' : range}
                onValueChange={(value) => {
                    if (value) {
                        visitRange(slug, view, {
                            range:
                                value === DEFAULT_TIME_RANGE
                                    ? undefined
                                    : value,
                        });
                    }
                }}
                aria-label={t('timeRange.label')}
            >
                {TIME_RANGES.map((item) => (
                    <ToggleGroupItem
                        key={item}
                        value={item}
                        className="px-2.5"
                        aria-label={t(`timeRange.last.${item}`)}
                    >
                        {t(`timeRange.option.${item}`)}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
        </div>
    );
}

/**
 * Drag to zoom (#248) on the charts of a range tab: the dragged buckets, from the start of the
 * first to the end of the last, become the page's custom range. A click on one bucket (#249)
 * narrows the page to it and scrolls to `rowsId`, the table of the rows behind the chart. The
 * server bounds the range like any custom range (retention, 14 days, now).
 */
export function useZoomToRange(
    slug: string,
    view: RangeView,
    rowsId?: string,
): (fromLabel: string, toLabel: string) => void {
    const { rangeWindow } = usePage<{ rangeWindow: TimeRangeWindow }>().props;

    return (fromLabel, toLabel) =>
        visitRange(
            slug,
            view,
            {
                from: new Date(fromLabel).toISOString(),
                to: new Date(
                    new Date(toLabel).getTime() +
                        rangeWindow.bucketSeconds * 1000,
                ).toISOString(),
            },
            fromLabel === toLabel ? rowsId : undefined,
        );
}

/**
 * "View ↗" in a chart card (#249): a link to the table of the rows behind the chart on the same
 * page, so the URL and its range stay; the keyboard path next to a click on a bucket.
 */
export function ChartRowsLink({
    rowsId,
    label,
}: {
    rowsId: string;
    /** Accessible name, e.g. "View routes". */
    label: string;
}) {
    const { t } = useTranslation();

    return (
        <CardAction>
            <Button variant="outline" size="sm" asChild>
                <a href={`#${rowsId}`} aria-label={label}>
                    {t('charts.view')}
                    <ArrowUpRight className="size-3.5" />
                </a>
            </Button>
        </CardAction>
    );
}

/**
 * The axis and tooltip text of a chart bucket: the date for daily buckets, the date and time
 * when the window spans more than a day, else the time.
 */
function bucketFormatter(
    bucketSeconds: number,
    spanSeconds: number,
): (at: string) => string {
    return (at) => {
        const date = new Date(at);

        if (bucketSeconds >= DAY_SECONDS) {
            return date.toLocaleDateString(undefined, {
                day: '2-digit',
                month: 'short',
            });
        }

        if (spanSeconds > DAY_SECONDS) {
            return date.toLocaleString(undefined, {
                day: '2-digit',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
            });
        }

        return date.toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
        });
    };
}

/**
 * The texts of the page's range (#113, #247): `headline` ("Last 24 hours" or the custom span),
 * `bucket` ("per hour"), `during` ("in the last 24 hours" or "from … to …") and `bucketText`, the
 * axis and tooltip text of a bucket. A preset reads its own keys; a custom range is described by
 * its span and bucket size (`rangeWindow`).
 */
export function useRangeTexts(range: TimeRange): {
    headline: string;
    bucket: string;
    during: string;
    bucketText: (at: string) => string;
} {
    const { t } = useTranslation();
    const { rangeWindow } = usePage<{ rangeWindow: TimeRangeWindow }>().props;
    const spanSeconds =
        (new Date(rangeWindow.to).getTime() -
            new Date(rangeWindow.from).getTime()) /
        1000;
    const bucketText = bucketFormatter(rangeWindow.bucketSeconds, spanSeconds);

    if (range !== 'custom') {
        return {
            headline: t(`timeRange.last.${range}`),
            bucket: t(`timeRange.bucket.${range}`),
            during: t(`timeRange.during.${range}`),
            bucketText,
        };
    }

    const format = new Intl.DateTimeFormat(undefined, {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
    const span = `${format.format(new Date(rangeWindow.from))} – ${format.format(new Date(rangeWindow.to))}`;

    return {
        headline: span,
        bucket: t(`timeRange.bucketSeconds.${rangeWindow.bucketSeconds}`),
        during: t('timeRange.custom.during', { span }),
        bucketText,
    };
}

/**
 * One chart marker per bucket with deploys (#106), named after their releases (shortened),
 * oldest first. A deploy without a release is named `unnamed`.
 */
export function deployMarkers(
    deploys: RuntimeDeploy[],
    unnamed: string,
): ChartMarker[] {
    const byBucket = new Map<string, string[]>();

    for (const deploy of deploys) {
        const names = byBucket.get(deploy.bucket) ?? [];
        names.push(
            deploy.release === null ? unnamed : shortRelease(deploy.release),
        );
        byBucket.set(deploy.bucket, names);
    }

    return [...byBucket].map(([bucket, names]) => ({
        at: bucket,
        name: [...new Set(names)].join(', '),
    }));
}
