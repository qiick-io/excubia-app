import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { trendOf } from '@/components/stat-card';
import { Card } from '@/components/ui/card';
import type { FormatNumber } from '@/hooks/use-number-format';
import { cn } from '@/lib/utils';

/**
 * A change against the previous period. `direction` is what happened to the number;
 * `tone` is whether that is good news, which depends on the metric: fewer open
 * findings is good, fewer requests is neither.
 */
export type KpiDelta = {
    /** The signed change, e.g. "-2.2%" or "+2". */
    label: string;
    direction: 'up' | 'down' | 'flat';
    tone: 'good' | 'bad' | 'neutral';
};

/**
 * The delta for a KPI tile, or null when there is no previous period to compare with.
 * `isIncreaseGood` says what an increase means for this metric (more checks passing:
 * true; more exceptions: false; more requests: null, neither).
 */
export function kpiDeltaOf(
    value: number,
    previous: number | null,
    isIncreaseGood: boolean | null,
    formatNumber: FormatNumber,
): KpiDelta | null {
    const trend = trendOf(value, previous, formatNumber);

    if (trend === null) {
        return null;
    }

    if (trend.delta === 0) {
        return { label: trend.label, direction: 'flat', tone: 'neutral' };
    }

    const isIncrease = trend.delta > 0;

    return {
        label: trend.label,
        direction: isIncrease ? 'up' : 'down',
        tone:
            isIncreaseGood === null
                ? 'neutral'
                : isIncrease === isIncreaseGood
                  ? 'good'
                  : 'bad',
    };
}

const DELTA_ICON = { up: ArrowUpRight, down: ArrowDownRight, flat: Minus };

const DELTA_TONE_CLASS = {
    good: 'text-success',
    bad: 'text-destructive',
    neutral: 'text-muted-foreground',
};

/**
 * One key number of a dashboard row: label, large value, the change against the
 * previous period, one short secondary line, and optionally a 30-day `Sparkline` that
 * bleeds to the tile's edges. The arrow and the sign carry the direction, the colour
 * only adds whether it is good news, so colour never carries the reading alone.
 */
export function KpiTile({
    label,
    value,
    delta,
    secondary,
    chart,
    className,
}: {
    label: string;
    /** The formatted value; a ratio may render its denominator muted ("25/41"). */
    value: ReactNode;
    delta?: KpiDelta | null;
    /** One short line: a breakdown, a comparison period or a scope. */
    secondary?: ReactNode;
    /** A `Sparkline` of the last 30 days; leave it out when there is no trend to show. */
    chart?: ReactNode;
    className?: string;
}) {
    const DeltaIcon = delta ? DELTA_ICON[delta.direction] : null;

    return (
        <Card className={cn('gap-0 overflow-hidden py-0', className)}>
            <div className="flex flex-col gap-1 p-4 pb-3">
                <div className="text-sm text-muted-foreground">{label}</div>
                <div className="flex flex-wrap items-baseline gap-x-2">
                    <div className="text-3xl font-semibold tracking-tight tabular-nums">
                        {value}
                    </div>
                    {delta && DeltaIcon ? (
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 text-xs font-medium tabular-nums',
                                DELTA_TONE_CLASS[delta.tone],
                            )}
                        >
                            <DeltaIcon
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            {delta.label}
                        </span>
                    ) : null}
                </div>
                {secondary ? (
                    <div className="text-xs text-muted-foreground">
                        {secondary}
                    </div>
                ) : null}
            </div>
            {chart ? <div className="mt-auto">{chart}</div> : null}
        </Card>
    );
}
