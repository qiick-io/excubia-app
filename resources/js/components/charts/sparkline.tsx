import { useId } from 'react';
import { Area, AreaChart, YAxis } from 'recharts';
import { ChartContainer } from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { cn } from '@/lib/utils';

const SPARKLINE_CONFIG = {
    value: { label: 'Value', color: 'var(--chart-1)' },
} satisfies ChartConfig;

/**
 * A 30-day trend under a key number: one area, no axes, grid or tooltip, because it
 * shows the shape of the period and the number above it is the reading. Hidden from
 * screen readers; the tile states the period in its text. Never animated, so it
 * respects reduced motion by construction.
 */
export function Sparkline({
    values,
    className,
}: {
    /** One value per day, oldest first. */
    values: number[];
    className?: string;
}) {
    const gradientId = `sparkline-${useId().replace(/:/g, '')}`;
    const points = values.map((value, dayIndex) => ({ dayIndex, value }));

    return (
        <ChartContainer
            config={SPARKLINE_CONFIG}
            aria-hidden="true"
            className={cn('aspect-auto h-12 w-full', className)}
        >
            <AreaChart
                data={points}
                // Decorative: no Tab stop inside an aria-hidden chart.
                accessibilityLayer={false}
                margin={{ top: 2, right: 0, bottom: 0, left: 0 }}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor="var(--color-value)"
                            stopOpacity={0.3}
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--color-value)"
                            stopOpacity={0}
                        />
                    </linearGradient>
                </defs>
                <YAxis hide domain={['dataMin', 'dataMax']} />
                <Area
                    dataKey="value"
                    type="monotone"
                    stroke="var(--color-value)"
                    strokeWidth={1.5}
                    fill={`url(#${gradientId})`}
                    isAnimationActive={false}
                />
            </AreaChart>
        </ChartContainer>
    );
}
