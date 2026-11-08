import { useTranslation } from 'react-i18next';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
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
import type { Severity } from '@/types';

export type SeverityChartPoint = { label: string } & Record<Severity, number>;

/** A vertical marker on one point of the chart, such as a deploy (#106). */
export type ChartMarker = {
    /** The `label` of the point it marks. */
    at: string;
    /** Short text drawn at the top of the line, so colour is not the only signal. */
    name: string;
};

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
}: {
    data: ({ label: string } & Record<string, number | string>)[];
    series: ChartSeries[];
    /** Accessible summary of what the chart shows. */
    label: string;
    markers?: ChartMarker[];
    formatLabel?: (label: string) => string;
    /** Axis and tooltip text of a value, for decimal series such as a cost; whole numbers otherwise. */
    formatValue?: (value: number) => string;
}) {
    const order = series.map((item) => item.key);
    const last = order[order.length - 1];

    return (
        <div role="img" aria-label={label} className="h-full w-full">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data}>
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
                        labelFormatter={
                            formatLabel
                                ? (value) =>
                                      typeof value === 'string'
                                          ? formatLabel(value)
                                          : value
                                : undefined
                        }
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
