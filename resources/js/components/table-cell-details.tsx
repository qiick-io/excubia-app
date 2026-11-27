import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerBody,
    DrawerClose,
    DrawerContent,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
import {
    Popover,
    PopoverContent,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useIsMobile } from '@/hooks/use-mobile';
import { useScrollRegion } from '@/hooks/use-scroll-region';
import { barWidthPercent } from '@/lib/table-cell-details';

/**
 * Table cell details (optional pattern `table-cell-details`,
 * docs/optional/table-cell-details.md).
 *
 * A report cell that holds a list (the largest tables, the packages blocking
 * an upgrade, one line per disk) would make its row grow until the table can
 * no longer be scanned. The cell keeps one line instead: a summary, then a
 * ghost `icon-sm` button that opens the full detail in a narrow `Popover`
 * (a bottom `Drawer` on mobile):
 * - `list`: the names, one per line;
 * - `bar`: horizontal bars for sizes or shares, each with its visible value
 *   label (bars compare close values, which a pie cannot).
 *
 * Every label arrives translated from the app; the item adds none of its own
 * except the drawer's Close button.
 */

export type TableCellDetailsItem = {
    id: string;
    /** Already translated. */
    label: string;
    /** `bar` variant: the number the bar is scaled from. */
    value?: number;
    /** `bar` variant: the formatted value shown next to the label ("0.08 MB"). */
    valueLabel?: string;
};

export type TableCellDetailsVariant = 'list' | 'bar';

type TableCellDetailsProps = {
    items: TableCellDetailsItem[];
    variant?: TableCellDetailsVariant;
    /**
     * The one line in the cell: a total, a count ("12 packages"). Defaults to
     * the first item followed by "+N".
     */
    summary?: ReactNode;
    /** Heading of the popover or drawer ("Largest tables"). */
    title: string;
    /**
     * The button's accessible name: the summary and its context
     * ("Show 5 largest tables").
     */
    triggerLabel: string;
};

/**
 * One-line summary plus the button that opens the full detail.
 */
export function TableCellDetails({
    items,
    variant = 'list',
    summary,
    title,
    triggerLabel,
}: TableCellDetailsProps) {
    const isMobile = useIsMobile();
    const { t } = useTranslation();

    const visibleSummary = summary ?? <FirstItemSummary items={items} />;

    // Nothing to detail: the cell is just its summary, without a dead button.
    if (items.length === 0) {
        return <span className="truncate">{visibleSummary}</span>;
    }

    const trigger = (
        <Button
            variant="ghost"
            size="icon-sm"
            aria-label={triggerLabel}
            className="text-muted-foreground"
        >
            <ChevronDown aria-hidden="true" />
        </Button>
    );

    return (
        <div className="flex min-w-0 items-center gap-1">
            <span className="min-w-0 truncate">{visibleSummary}</span>
            {isMobile ? (
                <Drawer direction="bottom">
                    <DrawerTrigger asChild>{trigger}</DrawerTrigger>
                    <DrawerContent className="inset-x-0 bottom-0 h-auto max-h-[80dvh] rounded-t-xl border-t">
                        <DrawerHeader>
                            <DrawerTitle className="text-base">
                                {title}
                            </DrawerTitle>
                        </DrawerHeader>
                        <DrawerBody className="pt-4">
                            <DetailItems items={items} variant={variant} />
                        </DrawerBody>
                        <DrawerFooter>
                            <DrawerClose asChild>
                                <Button variant="outline">
                                    {t('a11y.close')}
                                </Button>
                            </DrawerClose>
                        </DrawerFooter>
                    </DrawerContent>
                </Drawer>
            ) : (
                <Popover>
                    <PopoverTrigger asChild>{trigger}</PopoverTrigger>
                    <PopoverContent
                        align="start"
                        className="flex w-auto max-w-80 min-w-56 flex-col gap-2 p-0"
                    >
                        <PopoverTitle className="px-3 pt-3 text-sm">
                            {title}
                        </PopoverTitle>
                        <ScrollingDetailItems items={items} variant={variant} />
                    </PopoverContent>
                </Popover>
            )}
        </div>
    );
}

/**
 * The default summary: the first item, then "+N" for the others.
 */
function FirstItemSummary({ items }: { items: TableCellDetailsItem[] }) {
    if (items.length === 0) {
        return null;
    }

    const otherItemCount = items.length - 1;

    return (
        <>
            {items[0].label}
            {otherItemCount > 0 ? (
                <span className="text-muted-foreground tabular-nums">
                    {' '}
                    +{otherItemCount}
                </span>
            ) : null}
        </>
    );
}

/**
 * The popover's items, scrolling past about ten rows. The scroll container
 * joins the tab order only while it overflows.
 */
function ScrollingDetailItems({
    items,
    variant,
}: {
    items: TableCellDetailsItem[];
    variant: TableCellDetailsVariant;
}) {
    const scrollRegionRef = useScrollRegion<HTMLDivElement>();

    return (
        <div
            ref={scrollRegionRef}
            className="max-h-80 overflow-y-auto overscroll-contain px-3 pb-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
        >
            <DetailItems items={items} variant={variant} />
        </div>
    );
}

/**
 * The full detail: a plain list of names, or a bar per item scaled against
 * the largest value.
 */
function DetailItems({
    items,
    variant,
}: {
    items: TableCellDetailsItem[];
    variant: TableCellDetailsVariant;
}) {
    if (variant === 'list') {
        return (
            <ul className="grid gap-1 text-sm">
                {items.map((item) => (
                    <li key={item.id} className="py-0.5 break-words">
                        {item.label}
                    </li>
                ))}
            </ul>
        );
    }

    const largestValue = Math.max(0, ...items.map((item) => item.value ?? 0));

    return (
        <ul className="grid gap-2 text-sm">
            {items.map((item) => (
                <li key={item.id} className="grid gap-1">
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="min-w-0 break-words">
                            {item.label}
                        </span>
                        <span className="shrink-0 text-muted-foreground tabular-nums">
                            {item.valueLabel ?? item.value}
                        </span>
                    </div>
                    {/* The value label above is what assistive tech reads. */}
                    <div
                        aria-hidden="true"
                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{
                                width: `${barWidthPercent(item.value ?? 0, largestValue)}%`,
                            }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}
