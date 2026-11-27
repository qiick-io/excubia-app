import {
    ArrowRight,
    Ban,
    ChevronDown,
    CircleCheck,
    CircleDashed,
    CircleDot,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Separator } from '@/components/ui/separator';
import { DEFAULT_VISIBLE_CHIP_COUNT, splitTimelineChips } from '@/lib/timeline';
import { cn } from '@/lib/utils';

/**
 * Timeline (optional pattern `timeline`, docs/optional/timeline.md).
 *
 * Ordered steps (an upgrade plan, a setup checklist, a migration runbook)
 * read badly as a numbered list of long sentences: the status, the thing to
 * do and the packages it touches all blur into one line. The timeline gives
 * every step its own row on a vertical connector line: a marker (the step
 * number or an icon), a title, a status badge (icon and text, never colour
 * alone), an optional description and optional chips (`package 1.2.0 →
 * 1.3.0`). Past `visibleChipCount` the chips fold behind a "Show N more"
 * toggle, so a step that touches forty packages stays short.
 *
 * Steps are an `<ol>`, so screen readers announce how many there are and
 * where each one sits. Every label arrives translated from the app; the item
 * only translates its own: status names, "Show N more", the count.
 */

export type TimelineStepStatus =
    'done' | 'current' | 'pending' | 'warning' | 'blocked';

export type TimelineDensity = 'default' | 'compact';

export type TimelineChip = {
    id: string;
    /** Already translated, or a name ("spatie/laravel-activitylog"). */
    label: string;
    /** A value after the label ("5.1.5"), or the target of a change. */
    value?: string;
    /** With `value`, renders "previousValue → value" ("1.2.0 → 1.3.0"). */
    previousValue?: string;
};

export type TimelineStep = {
    id: string;
    title: ReactNode;
    description?: ReactNode;
    /** Without a status the step shows no badge and a neutral marker. */
    status?: TimelineStepStatus;
    /** Replaces the status's own name ("Ready" instead of "To do"). */
    statusLabel?: string;
    /** Marker icon; the marker shows the step number without one. */
    icon?: LucideIcon;
    chips?: TimelineChip[];
    /** A total next to the title ("12" packages), when the app has one. */
    count?: number;
    /** What the count counts, for screen readers ("12 packages"). */
    countLabel?: string;
};

type TimelineProps = Omit<ComponentProps<'ol'>, 'children'> & {
    steps: TimelineStep[];
    density?: TimelineDensity;
    /** Chips shown before "Show N more" folds the rest. */
    visibleChipCount?: number;
};

/**
 * Each status maps to a status badge variant and an icon of its own, so the
 * status reads in shape and text as well as colour.
 */
const STATUS_APPEARANCE: Record<
    TimelineStepStatus,
    {
        icon: LucideIcon;
        badgeVariant: 'success' | 'info' | 'muted' | 'warning' | 'destructive';
        markerBorderClass: string;
    }
> = {
    done: {
        icon: CircleCheck,
        badgeVariant: 'success',
        markerBorderClass: 'border-success',
    },
    current: {
        icon: CircleDot,
        badgeVariant: 'info',
        markerBorderClass: 'border-info',
    },
    pending: {
        icon: CircleDashed,
        badgeVariant: 'muted',
        markerBorderClass: 'border-border',
    },
    warning: {
        icon: TriangleAlert,
        badgeVariant: 'warning',
        markerBorderClass: 'border-warning',
    },
    blocked: {
        icon: Ban,
        badgeVariant: 'destructive',
        markerBorderClass: 'border-destructive',
    },
};

const DENSITY_CLASSES: Record<
    TimelineDensity,
    { marker: string; markerIcon: string; header: string; stepGap: string }
> = {
    default: {
        marker: 'size-8 text-sm',
        markerIcon: 'size-4',
        header: 'min-h-8',
        stepGap: 'pb-6',
    },
    compact: {
        marker: 'size-6 text-xs',
        markerIcon: 'size-3.5',
        header: 'min-h-6',
        stepGap: 'pb-3',
    },
};

/**
 * A vertical ordered list of steps joined by a connector line.
 */
export function Timeline({
    steps,
    density = 'default',
    visibleChipCount = DEFAULT_VISIBLE_CHIP_COUNT,
    className,
    ...listProps
}: TimelineProps) {
    return (
        <ol className={cn('flex flex-col', className)} {...listProps}>
            {steps.map((step, stepIndex) => (
                <TimelineStepItem
                    key={step.id}
                    step={step}
                    stepNumber={stepIndex + 1}
                    isLastStep={stepIndex === steps.length - 1}
                    density={density}
                    visibleChipCount={visibleChipCount}
                />
            ))}
        </ol>
    );
}

/**
 * One step: the marker column (marker, then the connector down to the next
 * step) beside the step's content.
 */
function TimelineStepItem({
    step,
    stepNumber,
    isLastStep,
    density,
    visibleChipCount,
}: {
    step: TimelineStep;
    stepNumber: number;
    isLastStep: boolean;
    density: TimelineDensity;
    visibleChipCount: number;
}) {
    const densityClasses = DENSITY_CLASSES[density];
    const MarkerIcon = step.icon;

    return (
        <li
            aria-current={step.status === 'current' ? 'step' : undefined}
            className="flex gap-3"
        >
            {/* The list position already gives the number to screen readers. */}
            <div aria-hidden="true" className="flex flex-col items-center">
                <span
                    className={cn(
                        'flex shrink-0 items-center justify-center rounded-full border-2 bg-background font-medium text-foreground tabular-nums',
                        densityClasses.marker,
                        step.status
                            ? STATUS_APPEARANCE[step.status].markerBorderClass
                            : 'border-border',
                    )}
                >
                    {MarkerIcon ? (
                        <MarkerIcon className={densityClasses.markerIcon} />
                    ) : (
                        stepNumber
                    )}
                </span>
                {isLastStep ? null : (
                    <Separator orientation="vertical" className="my-1 flex-1" />
                )}
            </div>
            <div
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-1.5',
                    isLastStep ? null : densityClasses.stepGap,
                )}
            >
                <div
                    className={cn(
                        'flex flex-wrap items-center gap-x-2 gap-y-1',
                        densityClasses.header,
                    )}
                >
                    <div className="min-w-0 text-sm font-medium wrap-break-word">
                        {step.title}
                    </div>
                    {step.count === undefined ? null : (
                        <StepCount
                            count={step.count}
                            countLabel={step.countLabel}
                        />
                    )}
                    {step.status ? (
                        <StepStatus
                            status={step.status}
                            statusLabel={step.statusLabel}
                        />
                    ) : null}
                </div>
                {step.description ? (
                    <div className="text-sm wrap-break-word text-muted-foreground">
                        {step.description}
                    </div>
                ) : null}
                {step.chips && step.chips.length > 0 ? (
                    <StepChips
                        chips={step.chips}
                        visibleChipCount={visibleChipCount}
                    />
                ) : null}
            </div>
        </li>
    );
}

/** The status badge: icon and text in the status colour. */
function StepStatus({
    status,
    statusLabel,
}: {
    status: TimelineStepStatus;
    statusLabel?: string;
}) {
    const { t } = useTranslation();
    const { icon: StatusIcon, badgeVariant } = STATUS_APPEARANCE[status];

    return (
        <Badge variant={badgeVariant}>
            <StatusIcon aria-hidden="true" />
            {statusLabel ?? t(`timeline.status.${status}`)}
        </Badge>
    );
}

/**
 * The total next to the title, in the UI language's number format. Screen
 * readers hear what it counts instead of a bare number.
 */
function StepCount({
    count,
    countLabel,
}: {
    count: number;
    countLabel?: string;
}) {
    const { t, i18n } = useTranslation();

    return (
        <Badge variant="muted" className="tabular-nums">
            <span aria-hidden="true">
                {new Intl.NumberFormat(i18n.language).format(count)}
            </span>
            <span className="sr-only">
                {countLabel ?? t('timeline.itemCount', { count })}
            </span>
        </Badge>
    );
}

/**
 * The step's chips: the first `visibleChipCount` always shown, the rest in a
 * Collapsible opened by a "Show N more" button (`aria-expanded`).
 */
function StepChips({
    chips,
    visibleChipCount,
}: {
    chips: TimelineChip[];
    visibleChipCount: number;
}) {
    const { t } = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const { visibleChips, foldedChips } = splitTimelineChips(
        chips,
        visibleChipCount,
    );

    if (foldedChips.length === 0) {
        return <ChipList chips={visibleChips} />;
    }

    return (
        <Collapsible
            open={isOpen}
            onOpenChange={setIsOpen}
            className="flex flex-col items-start gap-1.5"
        >
            <ChipList chips={visibleChips} />
            <CollapsibleContent className="w-full">
                <ChipList chips={foldedChips} />
            </CollapsibleContent>
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="-ml-2 text-muted-foreground"
                >
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'transition-transform',
                            isOpen ? 'rotate-180' : null,
                        )}
                    />
                    {isOpen
                        ? t('timeline.showLess')
                        : t('timeline.showMore', { count: foldedChips.length })}
                </Button>
            </CollapsibleTrigger>
        </Collapsible>
    );
}

/** Chips as a list; long names wrap instead of widening the page. */
function ChipList({ chips }: { chips: TimelineChip[] }) {
    return (
        <ul className="flex flex-wrap gap-1.5">
            {chips.map((chip) => (
                <li key={chip.id} className="max-w-full">
                    <Badge
                        variant="outline"
                        className="max-w-full justify-start font-normal whitespace-normal"
                    >
                        <span className="font-medium wrap-anywhere">
                            {chip.label}
                        </span>
                        <ChipValue chip={chip} />
                    </Badge>
                </li>
            ))}
        </ul>
    );
}

/**
 * "5.1.5", or "1.2.0 → 1.3.0" for a change. The arrow is drawn; screen
 * readers hear "from 1.2.0 to 1.3.0" instead.
 */
function ChipValue({ chip }: { chip: TimelineChip }) {
    const { t } = useTranslation();

    if (!chip.value) {
        return null;
    }

    if (!chip.previousValue) {
        return (
            <span className="text-muted-foreground tabular-nums">
                {chip.value}
            </span>
        );
    }

    return (
        <>
            <span
                aria-hidden="true"
                className="inline-flex items-center gap-1 text-muted-foreground tabular-nums"
            >
                {chip.previousValue}
                <ArrowRight className="size-3" />
                {chip.value}
            </span>
            <span className="sr-only">
                {t('timeline.valueChange', {
                    from: chip.previousValue,
                    to: chip.value,
                })}
            </span>
        </>
    );
}
