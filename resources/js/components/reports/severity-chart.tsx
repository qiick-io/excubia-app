import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ReferenceArea,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    SEVERITIES,
    SEVERITY_SCALE,
} from '@/components/reports/severity-badge';
import { cn } from '@/lib/utils';
import type { Severity } from '@/types';

export type SeverityChartPoint = { label: string } & Record<Severity, number>;

/** A vertical marker on one point of the chart, such as a deploy (#106). */
export type ChartMarker = {
    /** The `label` of the point it marks. */
    at: string;
    /** Short text drawn at the top of the line, so colour is not the only signal. */
    name: string;
};

/** The tooltip title of a bucket: its label through `formatLabel`, a number as is. */
function bucketText(
    value: unknown,
    formatLabel?: (label: string) => string,
): string {
    if (typeof value === 'string') {
        return formatLabel ? formatLabel(value) : value;
    }

    return typeof value === 'number' ? value.toLocaleString() : '';
}

/** Called with the labels of the first and last bucket of a span dragged across a chart. */
export type ChartZoomHandler = (fromLabel: string, toLabel: string) => void;

type DragHandlerState = { activeLabel?: string | number };

/**
 * Drag to zoom (#248): mouse down on a bucket starts a selection, moving extends it (drawn as a
 * ReferenceArea), mouse up hands the first and last bucket to `onZoom`; a click without a drag
 * hands the clicked bucket twice (#249). Escape or leaving the chart cancels. Mouse only:
 * keyboard and screen reader users pick the same range with the time range picker and reach the
 * rows with the card's View link.
 */
function useDragZoom(onZoom: ChartZoomHandler | undefined) {
    const [start, setStart] = useState<string | null>(null);
    const [end, setEnd] = useState<string | null>(null);
    const cancel = (): void => {
        setStart(null);
        setEnd(null);
    };

    useEffect(() => {
        if (start === null) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                cancel();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [start]);

    if (onZoom === undefined) {
        return { chartProps: {}, area: null, className: '' };
    }

    const labelOf = (state: DragHandlerState): string | null =>
        state.activeLabel === undefined ? null : String(state.activeLabel);

    return {
        chartProps: {
            onMouseDown: (state: DragHandlerState) => {
                setStart(labelOf(state));
                setEnd(null);
            },
            onMouseMove: (state: DragHandlerState) => {
                if (start !== null) {
                    setEnd(labelOf(state));
                }
            },
            onMouseUp: () => {
                if (start !== null) {
                    const last = end ?? start;
                    const [first, final] =
                        start < last ? [start, last] : [last, start];
                    onZoom(first, final);
                }

                cancel();
            },
            onMouseLeave: cancel,
        },
        area:
            start !== null && end !== null ? (
                <ReferenceArea
                    x1={start}
                    x2={end}
                    fill="var(--color-primary)"
                    fillOpacity={0.15}
                    stroke="var(--color-primary)"
                    strokeOpacity={0.4}
                    ifOverflow="extendDomain"
                />
            ) : null,
        className: 'cursor-crosshair select-none',
    };
}

export type ChartSeries = {
    key: string;
    label: string;
    /** A CSS colour, always a theme variable. */
    color: string;
};

/**
 * Stacked bars, one bar per point, styled like the externa-core dashboard charts: dashed
 * horizontal grid, small axis ticks, theme tokens for grid, axes and tooltip. The legend keeps
 * the series order and its text stays in a text token; the tooltip names every series, so
 * colour is never the only signal. Markers are dashed vertical lines with their name on top;
 * `formatLabel` turns a point's `label` into the axis and tooltip text, so labels can be unique
 * keys (an ISO hour) that markers point at.
 */
export function StackedBarChart({
    data,
    series,
    label,
    markers = [],
    formatLabel,
    formatValue,
    totalLabel,
    onZoom,
}: {
    data: ({ label: string } & Record<string, number | string>)[];
    series: ChartSeries[];
    /** Accessible summary of what the chart shows. */
    label: string;
    markers?: ChartMarker[];
    formatLabel?: (label: string) => string;
    /** Axis and tooltip text of a value, for decimal series such as a cost; whole numbers otherwise. */
    formatValue?: (value: number) => string;
    /** When set, the tooltip title adds the bucket's total under this name (#251). */
    totalLabel?: string;
    /** Drag across buckets to zoom the page into them (#248); the labels must be bucket times. */
    onZoom?: ChartZoomHandler;
}) {
    const order = series.map((item) => item.key);
    const last = order[order.length - 1];
    const zoom = useDragZoom(onZoom);

    return (
        <div
            role="img"
            aria-label={label}
            className={cn('h-full w-full', zoom.className)}
        >
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data} {...zoom.chartProps}>
                    <CartesianGrid
                        strokeDasharray="3 3"
                        vertical={false}
                        stroke="var(--color-border)"
                    />
                    <XAxis
                        dataKey="label"
                        tick={{
                            fontSize: 11,
                            fill: 'var(--color-muted-foreground)',
                        }}
                        stroke="var(--color-border)"
                        minTickGap={12}
                        tickFormatter={formatLabel}
                    />
                    <YAxis
                        allowDecimals={formatValue !== undefined}
                        // Grouped digits by default: a bare 60000 reads as 6000 at a glance (#194).
                        tickFormatter={
                            formatValue ??
                            ((value: number) => value.toLocaleString())
                        }
                        tick={{
                            fontSize: 11,
                            fill: 'var(--color-muted-foreground)',
                        }}
                        stroke="var(--color-border)"
                        width={40}
                    />
                    <Tooltip
                        cursor={{ fill: 'var(--color-accent)', opacity: 0.5 }}
                        contentStyle={{
                            background: 'var(--color-popover)',
                            border: '1px solid var(--color-border)',
                            borderRadius: 'var(--radius-md)',
                            color: 'var(--color-popover-foreground)',
                            fontSize: 12,
                        }}
                        labelStyle={{
                            color: 'var(--color-popover-foreground)',
                        }}
                        labelFormatter={(value, payload) => {
                            const text = bucketText(value, formatLabel);

                            if (totalLabel === undefined) {
                                return text;
                            }

                            const total = payload.reduce(
                                (sum, item) => sum + Number(item.value ?? 0),
                                0,
                            );

                            return `${text} · ${totalLabel} ${formatValue ? formatValue(total) : total.toLocaleString()}`;
                        }}
                        // Same order as the legend; text in a text token, not the series colour.
                        itemSorter={(item) =>
                            order.indexOf(String(item.dataKey))
                        }
                        itemStyle={{
                            color: 'var(--color-popover-foreground)',
                        }}
                        formatter={
                            formatValue
                                ? (value) =>
                                      typeof value === 'number'
                                          ? formatValue(value)
                                          : value
                                : undefined
                        }
                    />
                    <Legend
                        iconType="square"
                        iconSize={10}
                        itemSorter={(item) =>
                            order.indexOf(String(item.dataKey))
                        }
                        formatter={(value) => (
                            <span className="text-muted-foreground">
                                {value}
                            </span>
                        )}
                        wrapperStyle={{ fontSize: 12 }}
                    />
                    {series.map((item) => (
                        <Bar
                            key={item.key}
                            dataKey={item.key}
                            name={item.label}
                            stackId="stack"
                            fill={item.color}
                            stroke="var(--color-card)"
                            strokeWidth={1}
                            radius={item.key === last ? [4, 4, 0, 0] : 0}
                        />
                    ))}
                    {zoom.area}
                    {markers.map((marker) => (
                        <ReferenceLine
                            key={marker.at}
                            x={marker.at}
                            stroke="var(--color-primary)"
                            strokeDasharray="4 3"
                            label={{
                                value: marker.name,
                                position: 'insideTopLeft',
                                fontSize: 11,
                                fill: 'var(--color-foreground)',
                            }}
                        />
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

/**
 * Lines over the same buckets as StackedBarChart (#246), in its style: dashed grid, theme tokens,
 * legend and tooltip in series order with text in a text token, deploy markers. A null value is
 * a gap in the line, never a zero, so a bucket without data reads as missing.
 */
export function TimeLineChart({
    data,
    series,
    label,
    markers = [],
    formatLabel,
    formatValue,
    onZoom,
}: {
    data: ({ label: string } & Record<string, number | string | null>)[];
    series: ChartSeries[];
    /** Accessible summary of what the chart shows. */
    label: string;
    markers?: ChartMarker[];
    formatLabel?: (label: string) => string;
    /** Axis and tooltip text of a value, such as a duration. */
    formatValue: (value: number) => string;
    /** Drag across buckets to zoom the page into them (#248). */
    onZoom?: ChartZoomHandler;
}) {
    const order = series.map((item) => item.key);
    const zoom = useDragZoom(onZoom);

    return (
        <div
            role="img"
            aria-label={label}
            className={cn('h-full w-full', zoom.className)}
        >
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={data} {...zoom.chartProps}>
                    <CartesianGrid
                        strokeDasharray="3 3"
                        vertical={false}
                        stroke="var(--color-border)"
                    />
                    <XAxis
                        dataKey="label"
                        tick={{
                            fontSize: 11,
                            fill: 'var(--color-muted-foreground)',
                        }}
                        stroke="var(--color-border)"
                        minTickGap={12}
                        tickFormatter={formatLabel}
                    />
                    <YAxis
                        tickFormatter={formatValue}
                        tick={{
                            fontSize: 11,
                            fill: 'var(--color-muted-foreground)',
                        }}
                        stroke="var(--color-border)"
                        width={64}
                    />
                    <Tooltip
                        cursor={{ stroke: 'var(--color-border)' }}
                        contentStyle={{
                            background: 'var(--color-popover)',
                            border: '1px solid var(--color-border)',
                            borderRadius: 'var(--radius-md)',
                            color: 'var(--color-popover-foreground)',
                            fontSize: 12,
                        }}
                        labelStyle={{
                            color: 'var(--color-popover-foreground)',
                        }}
                        labelFormatter={(value) =>
                            bucketText(value, formatLabel)
                        }
                        itemSorter={(item) =>
                            order.indexOf(String(item.dataKey))
                        }
                        itemStyle={{
                            color: 'var(--color-popover-foreground)',
                        }}
                        formatter={(value) =>
                            typeof value === 'number'
                                ? formatValue(value)
                                : value
                        }
                    />
                    <Legend
                        iconType="plainline"
                        iconSize={12}
                        itemSorter={(item) =>
                            order.indexOf(String(item.dataKey))
                        }
                        formatter={(value) => (
                            <span className="text-muted-foreground">
                                {value}
                            </span>
                        )}
                        wrapperStyle={{ fontSize: 12 }}
                    />
                    {series.map((item) => (
                        <Line
                            key={item.key}
                            type="monotone"
                            dataKey={item.key}
                            name={item.label}
                            stroke={item.color}
                            strokeWidth={2}
                            // A bucket between two gaps has no segment: a dot keeps it visible.
                            dot={(dot: {
                                cx?: number;
                                cy?: number;
                                index?: number;
                            }) => {
                                const index = dot.index ?? 0;
                                const isIsolated =
                                    (data[index - 1]?.[item.key] ?? null) ===
                                        null &&
                                    (data[index + 1]?.[item.key] ?? null) ===
                                        null;

                                return isIsolated &&
                                    dot.cx !== undefined &&
                                    dot.cy !== undefined ? (
                                    <circle
                                        key={`${item.key}-${index}`}
                                        cx={dot.cx}
                                        cy={dot.cy}
                                        r={3}
                                        fill={item.color}
                                    />
                                ) : (
                                    <g key={`${item.key}-${index}`} />
                                );
                            }}
                            activeDot={{ r: 4 }}
                            connectNulls={false}
                            isAnimationActive={false}
                        />
                    ))}
                    {zoom.area}
                    {markers.map((marker) => (
                        <ReferenceLine
                            key={marker.at}
                            x={marker.at}
                            stroke="var(--color-primary)"
                            strokeDasharray="4 3"
                            label={{
                                value: marker.name,
                                position: 'insideTopLeft',
                                fontSize: 11,
                                fill: 'var(--color-foreground)',
                            }}
                        />
                    ))}
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}

/**
 * Findings per severity as stacked bars, one bar per point (a report, a day), in the colours of
 * the severity badges (`SEVERITY_SCALE`, #204).
 */
export function SeverityStackedBarChart({
    data,
    label,
}: {
    data: SeverityChartPoint[];
    label: string;
}) {
    const { t } = useTranslation();

    return (
        <StackedBarChart
            data={data}
            label={label}
            series={SEVERITIES.map((severity) => ({
                key: severity,
                label: t(`report.severity.${severity}`),
                color: SEVERITY_SCALE[severity].chartColor,
            }))}
        />
    );
}
