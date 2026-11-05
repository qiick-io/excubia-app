import { useTranslation } from 'react-i18next';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { SEVERITIES } from '@/components/reports/severity-badge';
import type { Severity } from '@/types';

export type SeverityChartPoint = { label: string } & Record<Severity, number>;

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
 * colour is never the only signal.
 */
export function StackedBarChart({
    data,
    series,
    label,
}: {
    data: ({ label: string } & Record<string, number | string>)[];
    series: ChartSeries[];
    /** Accessible summary of what the chart shows. */
    label: string;
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
                    />
                    <YAxis
                        allowDecimals={false}
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
                        // Same order as the legend; text in a text token, not the series colour.
                        itemSorter={(item) =>
                            order.indexOf(String(item.dataKey))
                        }
                        itemStyle={{
                            color: 'var(--color-popover-foreground)',
                        }}
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
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

/**
 * Findings per severity as stacked bars, one bar per point (a report, a day). The severity
 * colours are the ordinal red ramp in app.css (`--severity-chart-*`).
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
                color: `var(--severity-chart-${severity})`,
            }))}
        />
    );
}
