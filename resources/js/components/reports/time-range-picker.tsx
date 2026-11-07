import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { shortRelease } from '@/components/reports/release-label';
import type { ChartMarker } from '@/components/reports/severity-chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import projectsRoutes from '@/routes/projects';
import type { RuntimeDeploy, TimeRange } from '@/types';

/** The ranges of `TimeRangeEnum`, shortest first. */
export const TIME_RANGES: TimeRange[] = ['1h', '6h', '24h', '7d', '14d'];

export const DEFAULT_TIME_RANGE: TimeRange = '24h';

/**
 * The time range of the runtime, exceptions and schedule tabs (#113) as a segmented control,
 * like the dashboard period. A change reloads the tab with `?range=` (left out for the default),
 * so the range survives reloads, links and back.
 */
export function TimeRangePicker({
    slug,
    view,
    range,
}: {
    slug: string;
    view: 'runtime' | 'exceptions' | 'schedule';
    range: TimeRange;
}) {
    const { t } = useTranslation();

    return (
        <ToggleGroup
            type="single"
            variant="outline"
            value={range}
            onValueChange={(value) => {
                if (value) {
                    router.get(
                        projectsRoutes.view.url(
                            { project: slug, view },
                            {
                                query: {
                                    range:
                                        value === DEFAULT_TIME_RANGE
                                            ? undefined
                                            : value,
                                },
                            },
                        ),
                        {},
                        {
                            preserveScroll: true,
                            preserveState: true,
                            replace: true,
                        },
                    );
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
    );
}

/**
 * The axis and tooltip text of a chart bucket: the time up to 24 hours, the date and time for
 * 7 days, the date for 14 days.
 */
export function bucketLabel(range: TimeRange): (at: string) => string {
    return (at) => {
        const date = new Date(at);

        if (range === '14d') {
            return date.toLocaleDateString(undefined, {
                day: '2-digit',
                month: 'short',
            });
        }

        if (range === '7d') {
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
