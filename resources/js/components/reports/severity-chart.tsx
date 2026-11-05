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

/**
 * Findings per severity as stacked bars, one bar per point (a report, a day). Styled like the
 * externa-core dashboard charts: dashed horizontal grid, small axis ticks, theme tokens. The
 * severity colours are the ordinal red ramp in app.css; the legend and the tooltip name every
 * severity, so colour is never the only signal.
 */
export function SeverityStackedBarChart({
    data,
    label,
}: {
    data: SeverityChartPoint[];
    /** Accessible summary of what the chart shows. */
    label: string;
}) {
    const { t } = useTranslation();
    const lastSeverity = SEVERITIES[SEVERITIES.length - 1];

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
                        width={32}
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
                    />
                    <Legend
                        iconType="square"
                        iconSize={10}
                        // Worst first, like the badges; legend text stays in a text token.
                        itemSorter={(item) =>
                            SEVERITIES.indexOf(item.dataKey as Severity)
                        }
                        formatter={(value) => (
                            <span className="text-muted-foreground">
                                {value}
                            </span>
                        )}
                        wrapperStyle={{
                            fontSize: 12,
                            color: 'var(--color-muted-foreground)',
                        }}
                    />
                    {SEVERITIES.map((severity) => (
                        <Bar
                            key={severity}
                            dataKey={severity}
                            name={t(`report.severity.${severity}`)}
                            stackId="severity"
                            fill={`var(--severity-chart-${severity})`}
                            stroke="var(--color-card)"
                            strokeWidth={1}
                            radius={
                                severity === lastSeverity ? [4, 4, 0, 0] : 0
                            }
                        />
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
