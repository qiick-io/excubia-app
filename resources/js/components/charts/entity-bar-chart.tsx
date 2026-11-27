import { Bar, BarChart, LabelList, XAxis } from 'recharts';
import {
    AnnouncedTooltip,
    CHART_FOCUS_CLASS,
    ChartDataTable,
} from '@/components/charts/chart-data-table';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { cn } from '@/lib/utils';

/** One entity on the shared measure (a project and its open findings). */
export type EntityBarDatum = { label: string; value: number };

/**
 * Entities compared on one measure, as vertical bars in one colour: colour would
 * suggest the bars mean different things. Every bar carries its value on top, so
 * nothing waits for hover, and a hidden table gives screen readers the same numbers.
 * Only for comparing entities; change over time is a `TimeSeriesChart`, a share of a
 * whole a `SegmentedBar`. Not animated, so reduced motion holds by construction.
 */
export function EntityBarChart({
    title,
    valueLabel,
    entityColumnHeader,
    entities,
    formatValue = (value) => value.toLocaleString(),
    className,
}: {
    /** The comparison, read as the table caption ("Open findings by project"). */
    title: string;
    /** The measure's name in the tooltip and table ("Open findings"). */
    valueLabel: string;
    /** First column header of the screen-reader table ("Project"). */
    entityColumnHeader: string;
    /** In the order to show, usually largest first. */
    entities: EntityBarDatum[];
    formatValue?: (value: number) => string;
    className?: string;
}) {
    // About 36 characters of axis fit a 320px-wide plot; each bar gets its share.
    const maximumLabelLength = Math.max(
        4,
        Math.floor(36 / Math.max(1, entities.length)),
    );
    const config = {
        value: { label: valueLabel, color: 'var(--chart-1)' },
    } satisfies ChartConfig;

    return (
        <>
            <ChartContainer
                config={config}
                className={cn(CHART_FOCUS_CLASS, className)}
            >
                <BarChart
                    data={entities}
                    accessibilityLayer
                    title={title}
                    margin={{ top: 20, right: 0, bottom: 0, left: 0 }}
                >
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        interval={0}
                        tickMargin={8}
                        // Every bar keeps a name that fits its slot at 320px; tooltip and table keep it whole.
                        tickFormatter={(label: string) =>
                            label.length > maximumLabelLength
                                ? `${label.slice(0, maximumLabelLength - 1)}…`
                                : label
                        }
                    />
                    <ChartTooltip
                        cursor={false}
                        content={(tooltipProps) => (
                            <AnnouncedTooltip>
                                <ChartTooltipContent
                                    active={tooltipProps.active}
                                    payload={tooltipProps.payload}
                                    label={tooltipProps.label}
                                    formatter={(value) => (
                                        <span className="flex w-full justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                {valueLabel}
                                            </span>
                                            <span className="font-medium text-foreground tabular-nums">
                                                {formatValue(Number(value))}
                                            </span>
                                        </span>
                                    )}
                                />
                            </AnnouncedTooltip>
                        )}
                    />
                    <Bar
                        dataKey="value"
                        fill="var(--color-value)"
                        radius={4}
                        maxBarSize={48}
                        isAnimationActive={false}
                    >
                        <LabelList
                            dataKey="value"
                            position="top"
                            formatter={(value: unknown) =>
                                formatValue(Number(value))
                            }
                            className="fill-foreground tabular-nums"
                        />
                    </Bar>
                </BarChart>
            </ChartContainer>
            <ChartDataTable
                caption={title}
                columnHeaders={[entityColumnHeader, valueLabel]}
                rows={entities.map((entity) => ({
                    header: entity.label,
                    values: [formatValue(entity.value)],
                }))}
            />
        </>
    );
}
