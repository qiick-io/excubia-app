import { cn } from '@/lib/utils';

/**
 * The fixed semantic chart tones: finding severity, then check outcome. Each maps to a
 * `--chart-*` theme token validated for contrast and colour-blind separation in light
 * and dark, so a product never picks its own red for "critical".
 */
export type ChartTone =
    | 'critical'
    | 'high'
    | 'medium'
    | 'low'
    | 'info'
    | 'pass'
    | 'warn'
    | 'fail'
    | 'skipped';

/** Literal class names so Tailwind sees every tone. */
export const CHART_TONE_BACKGROUND: Record<ChartTone, string> = {
    critical: 'bg-chart-critical',
    high: 'bg-chart-high',
    medium: 'bg-chart-medium',
    low: 'bg-chart-low',
    info: 'bg-chart-info',
    pass: 'bg-chart-pass',
    warn: 'bg-chart-warn',
    fail: 'bg-chart-fail',
    skipped: 'bg-chart-skipped',
};

export type SegmentedBarSegment = {
    tone: ChartTone;
    label: string;
    value: number;
};

/**
 * A composition as one horizontal bar split into segments (severity, check outcomes),
 * with every count printed in the legend under it. The pattern's answer to pie and
 * donut charts: lengths on one baseline compare at a glance, angles do not. The bar is
 * decoration for sighted readers; the legend is the data, a list screen readers read.
 */
export function SegmentedBar({
    title,
    segments,
    className,
}: {
    /** What the bar splits, the legend list's accessible name ("Open findings by severity"). */
    title: string;
    segments: SegmentedBarSegment[];
    className?: string;
}) {
    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div
                aria-hidden="true"
                // The gaps show the card, so every segment keeps its 3:1 edge; an all-zero bar shows the muted track.
                className={cn(
                    'flex h-2 w-full gap-0.5 overflow-hidden rounded-full',
                    segments.every((segment) => segment.value === 0) &&
                        'bg-muted',
                )}
            >
                {/* Zero counts draw nothing; the legend still lists them ("Critical 0"). */}
                {segments
                    .filter((segment) => segment.value > 0)
                    .map((segment) => (
                        <div
                            key={segment.tone}
                            className={cn(
                                'h-full',
                                CHART_TONE_BACKGROUND[segment.tone],
                            )}
                            style={{ flexGrow: segment.value }}
                        />
                    ))}
            </div>
            <ul
                aria-label={title}
                className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
            >
                {segments.map((segment) => (
                    <li
                        key={segment.tone}
                        className="flex items-center gap-1.5"
                    >
                        <span
                            className={cn(
                                'size-2 shrink-0 rounded-sm',
                                CHART_TONE_BACKGROUND[segment.tone],
                            )}
                        />
                        {segment.label}{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {segment.value}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
