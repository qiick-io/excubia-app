import { useState } from 'react';
import type { ReactNode } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    AnnouncedTooltip,
    CHART_FOCUS_CLASS,
    ChartDataTable,
} from '@/components/charts/chart-data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

/** One stacked series (an agent, a project). Its colour follows its position, `--chart-1` … `--chart-5`. */
export type TimeSeriesSeries = { key: string; label: string };

/** One point in time: the date, then one number per series key. */
export type TimeSeriesRow = { date: string } & Record<string, number | string>;

/** One measure the switch offers (agent time, cost, tokens), with its own rows and unit. */
export type TimeSeriesMetric = {
    key: string;
    label: string;
    /** Formats values in the axis, tooltip, legend totals and table ("$12.40", "3h 20m"). */
    formatValue: (value: number) => string;
    rows: TimeSeriesRow[];
};

const SERIES_LIMIT = 5;

/** Colours are set on the marks themselves (see `seriesByKey`). */
const NO_CONFIG = {} satisfies ChartConfig;

/**
 * The sum of each series over the period, so the legend states the numbers instead of
 * leaving them on hover.
 */
export function seriesTotals(
    rows: TimeSeriesRow[],
    series: TimeSeriesSeries[],
): Record<string, number> {
    return Object.fromEntries(
        series.map((entry) => [
            entry.key,
            rows.reduce((sum, row) => sum + Number(row[entry.key] ?? 0), 0),
        ]),
    );
}

/**
 * Change over time for up to five series, stacked: a metric switch (one measure at a
 * time, never a second y-axis), a legend with each series' total or latest value and
 * toggle it, a tooltip, and loading and empty states. Recharts' accessibility layer
 * moves the tooltip with the arrow keys; a hidden table gives screen readers every
 * value. Not animated, so reduced motion holds by construction.
 *
 * @throws Error when given more than five series: fold the rest into "Other".
 */
export function TimeSeriesChart({
    title,
    series,
    metrics,
    formatDate,
    metricSwitchLabel,
    dateColumnHeader,
    legendValue = 'sum',
    isLoading = false,
    emptyState,
    className,
}: {
    title: string;
    series: TimeSeriesSeries[];
    metrics: TimeSeriesMetric[];
    /** Short date for the axis and tooltip ("Nov 3"). */
    formatDate: (date: string) => string;
    /** Accessible name of the metric switch ("Metric"). */
    metricSwitchLabel: string;
    /** First column header of the screen-reader table ("Date"). */
    dateColumnHeader: string;
    /** Stock metrics show the last snapshot because adding daily values counts the same items again. */
    legendValue?: 'sum' | 'latest';
    isLoading?: boolean;
    /** Shown instead of the plot when the period has no data (`PageEmptyState compact`). */
    emptyState: ReactNode;
    className?: string;
}) {
    const [metricKey, setMetricKey] = useState(metrics[0]?.key);
    const [hiddenSeriesKeys, setHiddenSeriesKeys] = useState<string[]>([]);

    if (series.length > SERIES_LIMIT) {
        throw new Error(
            `TimeSeriesChart takes at most ${SERIES_LIMIT} series; fold the rest into "Other".`,
        );
    }

    const metric =
        metrics.find((candidate) => candidate.key === metricKey) ?? metrics[0];
    const rows = metric?.rows ?? [];
    const legendValues =
        legendValue === 'latest'
            ? (rows.at(-1) ?? {})
            : seriesTotals(rows, series);
    // Recorded zeros are data; only a period without rows is empty.
    const isEmpty = rows.length === 0;
    const shownSeries = series.filter(
        (entry) => !hiddenSeriesKeys.includes(entry.key),
    );
    // A changed `series` prop can leave only hidden keys: then show them all again.
    const visibleSeries = shownSeries.length > 0 ? shownSeries : series;
    /*
     * Colour follows the series, not its rank, so hiding one never repaints the others.
     * Colours go straight onto the marks: series keys are caller data and never reach
     * ChartConfig, whose keys `ChartStyle` writes into a stylesheet unescaped.
     */
    const seriesByKey = Object.fromEntries(
        series.map((entry, seriesIndex) => [
            entry.key,
            { label: entry.label, color: `var(--chart-${seriesIndex + 1})` },
        ]),
    );
    const colorOf = (seriesKey: string) => seriesByKey[seriesKey]?.color;

    const toggleSeries = (seriesKey: string) =>
        setHiddenSeriesKeys((current) =>
            current.includes(seriesKey)
                ? current.filter((key) => key !== seriesKey)
                : [...current, seriesKey],
        );

    return (
        <Card className={cn('gap-4 py-4', className)}>
            <div className="flex flex-wrap items-center justify-between gap-2 px-4">
                <h2 className="text-sm font-medium">{title}</h2>
                {metrics.length > 1 ? (
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        aria-label={metricSwitchLabel}
                        value={metric?.key}
                        onValueChange={(value) => value && setMetricKey(value)}
                    >
                        {metrics.map((candidate) => (
                            <ToggleGroupItem
                                key={candidate.key}
                                value={candidate.key}
                                className="px-3"
                            >
                                {candidate.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                ) : null}
            </div>
            <div className="px-4">
                {isLoading ? (
                    <Skeleton className="h-64 w-full" />
                ) : isEmpty || !metric ? (
                    <div className="flex h-64 items-center justify-center">
                        {emptyState}
                    </div>
                ) : (
                    <>
                        <ChartContainer
                            config={NO_CONFIG}
                            className={CHART_FOCUS_CLASS}
                        >
                            <AreaChart
                                data={rows}
                                accessibilityLayer
                                title={title}
                                margin={{
                                    top: 4,
                                    right: 4,
                                    bottom: 0,
                                    left: 0,
                                }}
                            >
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="date"
                                    tickFormatter={formatDate}
                                    tickLine={false}
                                    axisLine={false}
                                    minTickGap={24}
                                />
                                <YAxis
                                    tickFormatter={metric.formatValue}
                                    tickLine={false}
                                    axisLine={false}
                                    width={64}
                                />
                                <ChartTooltip
                                    content={(tooltipProps) => (
                                        <AnnouncedTooltip>
                                            <ChartTooltipContent
                                                active={tooltipProps.active}
                                                payload={tooltipProps.payload}
                                                label={tooltipProps.label}
                                                labelFormatter={(_, payload) =>
                                                    formatDate(
                                                        String(
                                                            payload[0]?.payload
                                                                ?.date ?? '',
                                                        ),
                                                    )
                                                }
                                                formatter={(value, name) => (
                                                    <TooltipRow
                                                        color={colorOf(
                                                            String(name),
                                                        )}
                                                        label={
                                                            seriesByKey[
                                                                String(name)
                                                            ]?.label ?? name
                                                        }
                                                        value={metric.formatValue(
                                                            Number(value),
                                                        )}
                                                    />
                                                )}
                                            />
                                        </AnnouncedTooltip>
                                    )}
                                />
                                {visibleSeries.map((entry) => (
                                    <Area
                                        key={entry.key}
                                        dataKey={entry.key}
                                        type="monotone"
                                        stackId="total"
                                        stroke={colorOf(entry.key)}
                                        strokeWidth={1.5}
                                        fill={colorOf(entry.key)}
                                        fillOpacity={0.25}
                                        isAnimationActive={false}
                                    />
                                ))}
                            </AreaChart>
                        </ChartContainer>
                        <ChartDataTable
                            caption={`${title}, ${metric.label}`}
                            columnHeaders={[
                                dateColumnHeader,
                                ...series.map((entry) => entry.label),
                            ]}
                            rows={rows.map((row) => ({
                                header: formatDate(row.date),
                                values: series.map((entry) =>
                                    metric.formatValue(
                                        Number(row[entry.key] ?? 0),
                                    ),
                                ),
                            }))}
                        />
                    </>
                )}
            </div>
            {!isLoading && !isEmpty && metric ? (
                <ul aria-label={title} className="flex flex-wrap gap-1 px-3">
                    {series.map((entry) => {
                        const isVisible = visibleSeries.includes(entry);

                        return (
                            <li key={entry.key}>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    aria-pressed={isVisible}
                                    // The last visible series stays: an empty plot reads as "no data".
                                    disabled={
                                        isVisible && visibleSeries.length === 1
                                    }
                                    onClick={() => toggleSeries(entry.key)}
                                    className={cn(
                                        'gap-1.5 text-xs font-normal',
                                        !isVisible && 'text-muted-foreground',
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'size-2 shrink-0 rounded-sm border',
                                            !isVisible && 'bg-transparent',
                                        )}
                                        style={{
                                            borderColor: colorOf(entry.key),
                                            backgroundColor: isVisible
                                                ? colorOf(entry.key)
                                                : undefined,
                                        }}
                                    />
                                    {entry.label}{' '}
                                    <span
                                        className={cn(
                                            'font-medium tabular-nums',
                                            isVisible && 'text-foreground',
                                        )}
                                    >
                                        {metric.formatValue(
                                            Number(
                                                legendValues[entry.key] ?? 0,
                                            ),
                                        )}
                                    </span>
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </Card>
    );
}

/**
 * One tooltip line: swatch, series name, and the value in the metric's own unit (the
 * default row prints raw numbers, so "$12.40" would read 1240).
 */
function TooltipRow({
    color,
    label,
    value,
}: {
    color: string | undefined;
    label: ReactNode;
    value: string;
}) {
    return (
        <div className="flex w-full items-center gap-2">
            <span
                aria-hidden="true"
                className="size-2.5 shrink-0 rounded-sm"
                style={{ backgroundColor: color }}
            />
            <span className="flex-1 text-muted-foreground">{label}</span>
            <span className="font-medium text-foreground tabular-nums">
                {value}
            </span>
        </div>
    );
}
