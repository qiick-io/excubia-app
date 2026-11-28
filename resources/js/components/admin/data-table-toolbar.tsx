import { ListFilter, Search, X } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type DataTableToolbarProps = {
    search: string;
    onSearchChange: (value: string) => void;
    searchPlaceholder?: string;
    searchMaxLength?: number;
    selectedCount?: number;
    onClearSelection?: () => void;
    bulkActions?: ReactNode;
    /** Filters that always stay in the row, after the search (a status segmented control). */
    trailing?: ReactNode;
    /**
     * Secondary filters (facets, assignee, time range): inline after `trailing` while the
     * whole toolbar fits on one row, otherwise behind a "Filters" popover button.
     */
    collapsibleFilters?: ReactNode;
    /** How many of the `collapsibleFilters` are set; shown on the "Filters" button. */
    collapsibleFilterCount?: number;
    className?: string;
};

/**
 * The toolbar's layouts, widest first: everything in one row; the secondary filters behind
 * the "Filters" button; that plus the search on its own full-width row; and, when even the
 * always-visible filters do not fit beside "Filters", search and "Filters" alone with every
 * filter in the popover (narrow phones).
 */
const TOOLBAR_LAYOUTS = ['inline', 'collapsed', 'stacked', 'minimal'] as const;

export type DataTableToolbarLayout = (typeof TOOLBAR_LAYOUTS)[number];

/**
 * The toolbar layout for its measured widths: one step narrower while the content
 * overflows, one step wider once the toolbar is as wide as the wider layout needed when it
 * last overflowed. The hysteresis keeps two layouts from flipping back and forth at one
 * width.
 *
 * @param state.layout - The current layout.
 * @param state.clientWidth - The toolbar's visible width.
 * @param state.scrollWidth - The width its content needs in the current layout.
 * @param state.neededWidths - The `scrollWidth` each layout had when it last overflowed.
 */
export function nextToolbarLayout({
    layout,
    clientWidth,
    scrollWidth,
    neededWidths,
}: {
    layout: DataTableToolbarLayout;
    clientWidth: number;
    scrollWidth: number;
    neededWidths: Partial<Record<DataTableToolbarLayout, number>>;
}): DataTableToolbarLayout {
    const level = TOOLBAR_LAYOUTS.indexOf(layout);
    const isOverflowing = scrollWidth > clientWidth + 1;

    if (isOverflowing && level < TOOLBAR_LAYOUTS.length - 1) {
        return TOOLBAR_LAYOUTS[level + 1];
    }

    const widerLayout = TOOLBAR_LAYOUTS[level - 1];
    const widerNeededWidth = widerLayout
        ? neededWidths[widerLayout]
        : undefined;

    if (widerNeededWidth !== undefined && clientWidth >= widerNeededWidth) {
        return widerLayout;
    }

    return layout;
}

/**
 * Toolbar of an admin list (docs/list-page.md, "Toolbar"): search on the left, filters
 * grouped on the right, in one row. When the row does not fit, the `collapsibleFilters`
 * move into a "Filters" popover with the count of active ones; when even that does not fit
 * (phones, narrow cards), the search takes its own row above the filters. The toolbar never
 * wraps at random. While rows are selected the same row becomes the selection bar.
 *
 * The fit is measured with a `ResizeObserver` on the toolbar and its filter group, never
 * by counting items, so it follows the real labels in every language.
 *
 * @param props - See {@link DataTableToolbarProps}.
 */
export function DataTableToolbar({
    search,
    onSearchChange,
    searchPlaceholder = 'Search…',
    searchMaxLength = 255,
    selectedCount = 0,
    onClearSelection,
    bulkActions,
    trailing,
    collapsibleFilters,
    collapsibleFilterCount = 0,
    className,
}: DataTableToolbarProps) {
    const { t } = useTranslation();
    const hasSelection = selectedCount > 0;
    const toolbarRef = useRef<HTMLDivElement>(null);
    const filterGroupRef = useRef<HTMLDivElement>(null);
    const neededWidths = useRef<
        Partial<Record<DataTableToolbarLayout, number>>
    >({});
    const [layout, setLayout] = useState<DataTableToolbarLayout>('inline');
    const hasCollapsibleFilters = Boolean(collapsibleFilters);
    const isCollapsed = layout !== 'inline';
    const isStacked = layout === 'stacked';
    const isMinimal = layout === 'minimal';
    const hasPopoverFilters =
        hasCollapsibleFilters || (isMinimal && Boolean(trailing));

    // Measured before paint, so a narrower layout never flashes in the wider one.
    useLayoutEffect(() => {
        const toolbar = toolbarRef.current;

        if (!toolbar || hasSelection) {
            return;
        }

        const measure = (): void => {
            const nextLayout = nextToolbarLayout({
                layout,
                clientWidth: toolbar.clientWidth,
                scrollWidth: toolbar.scrollWidth,
                neededWidths: neededWidths.current,
            });

            if (nextLayout === layout) {
                return;
            }

            if (
                TOOLBAR_LAYOUTS.indexOf(nextLayout) >
                TOOLBAR_LAYOUTS.indexOf(layout)
            ) {
                neededWidths.current[layout] = toolbar.scrollWidth;
            }

            setLayout(nextLayout);
        };

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(toolbar);

        if (filterGroupRef.current) {
            observer.observe(filterGroupRef.current);
        }

        return () => observer.disconnect();
    }, [hasSelection, layout]);

    const hasFilterGroup = Boolean(trailing) || hasCollapsibleFilters;

    return (
        <div
            ref={toolbarRef}
            data-slot="data-table-toolbar"
            className={cn(
                'flex min-w-0 flex-1 items-center justify-between gap-2',
                isStacked ? 'flex-wrap' : 'flex-nowrap',
                className,
            )}
        >
            {hasSelection ? (
                <>
                    <Button
                        type="button"
                        variant="ghost"
                        className="shrink-0"
                        onClick={onClearSelection}
                    >
                        <X className="size-4" aria-hidden="true" />
                        {t('common.selectedCount', { count: selectedCount })}
                    </Button>
                    {bulkActions ? (
                        <div className="flex min-w-0 flex-nowrap items-center gap-2 overflow-x-auto">
                            {bulkActions}
                        </div>
                    ) : null}
                </>
            ) : (
                <div
                    className={cn(
                        'relative min-w-[8rem] flex-1',
                        isStacked ? 'basis-full' : 'max-w-xs',
                    )}
                >
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder={searchPlaceholder}
                        aria-label={searchPlaceholder}
                        maxLength={searchMaxLength}
                        className="pl-8"
                    />
                </div>
            )}
            {!hasSelection && hasFilterGroup ? (
                <div
                    ref={filterGroupRef}
                    className="flex shrink-0 flex-nowrap items-center gap-2"
                >
                    {isMinimal ? null : trailing}
                    {hasCollapsibleFilters && !isCollapsed
                        ? collapsibleFilters
                        : null}
                    {hasPopoverFilters && isCollapsed ? (
                        <Popover>
                            <PopoverTrigger asChild>
                                <Button type="button" variant="outline">
                                    <ListFilter
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('dataTable.filters')}
                                    {collapsibleFilterCount > 0 ? (
                                        <Badge
                                            variant="secondary"
                                            className="h-5 px-1.5 tabular-nums"
                                        >
                                            <span aria-hidden="true">
                                                {collapsibleFilterCount}
                                            </span>
                                            <span className="sr-only">
                                                {t('dataTable.activeFilters', {
                                                    count: collapsibleFilterCount,
                                                })}
                                            </span>
                                        </Badge>
                                    ) : null}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                align="end"
                                className="flex w-auto max-w-[calc(100vw-2rem)] flex-col items-start gap-3"
                            >
                                {isMinimal ? trailing : null}
                                {collapsibleFilters}
                            </PopoverContent>
                        </Popover>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
