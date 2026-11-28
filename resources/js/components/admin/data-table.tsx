import {
    flexRender,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import type {
    Column,
    ColumnDef,
    ColumnPinningState,
    OnChangeFn,
    RowData,
    RowSelectionState,
    SortingState,
} from '@tanstack/react-table';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useId, useRef } from 'react';
import type {
    CSSProperties,
    FocusEvent,
    HTMLAttributes,
    KeyboardEvent,
    ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';
import {
    TablePaginationFooter,
    TablePanel,
    hasTablePaginationFooter,
} from '@/components/layout/page-layout';
import type { TablePaginationState } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

declare module '@tanstack/react-table' {
    // Type parameters are required by the declaration merge.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface ColumnMeta<TData extends RowData, TValue> {
        /** Classes for the column's `<th>`. */
        headerClassName?: string;
        /** Classes for each of the column's `<td>`. */
        cellClassName?: string;
        /** Clicks inside the cell do not reach `onRowClick` (checkboxes, row actions). */
        isRowClickIgnored?: boolean;
    }
}

/** Server sort state: the sorted column id and its direction. */
export type DataTableSort = {
    id: string;
    direction: 'asc' | 'desc';
};

export type DataTableProps<TData> = {
    /** Column definitions; a sortable column needs `accessorKey` / `accessorFn` and `enableSorting: true`, and its id is the server sort field. */
    columns: ColumnDef<TData, unknown>[];
    data: TData[];
    /** Stable row id (the record key); row selection is keyed by it. */
    getRowId: (row: TData) => string;
    /** Current server sort; omit for a list without column sorting. */
    sort?: DataTableSort;
    /** Called with the new sort when a column header asks for one. */
    onSortChange?: (sort: DataTableSort) => void;
    /** Controlled row selection (`{ [rowId]: true }`); omit to disable selection. */
    rowSelection?: RowSelectionState;
    onRowSelectionChange?: OnChangeFn<RowSelectionState>;
    /** Server paginator; renders the shared pagination footer when it is needed. */
    pagination?: TablePaginationState;
    /** Opens a row by click or by pressing Enter or Space on the focused row. */
    onRowClick?: (row: TData) => void;
    /** Extra props per `<tr>` (`data-test`, classes). */
    getRowProps?: (row: TData) => HTMLAttributes<HTMLTableRowElement> & {
        [dataAttribute: `data-${string}`]: string | undefined;
    };
    /** Details shown in a full-width row under an expanded row (see {@link dataTableExpandColumn}). */
    renderExpandedRow?: (row: TData) => ReactNode;
    /** Shown in the table body when `data` is empty. */
    emptyState?: ReactNode;
    /**
     * Columns that stay in view while a wide table scrolls sideways, by column id
     * (`{ left: ['select', 'name'], right: ['actions'] }`). Left offsets add up the `size` of
     * the pinned columns before each one, so every left-pinned column but the last needs a
     * fixed width equal to its `size` (the select and expand columns set both).
     */
    columnPinning?: ColumnPinningState;
    className?: string;
};

/**
 * Sticky-cell props of a pinned column (docs/list-page.md, "Pinned columns"): native
 * `position: sticky` with the offset TanStack computes, plus the `data-pinned` attributes
 * the theme styles (opaque background, row tints, edge shadow while scrolled). Cells keep
 * their place in the DOM, so reading and tab order are unchanged.
 *
 * Every left-pinned column but the last renders at exactly its `size` (so the next column's
 * `left` offset matches the painted width). The last left-pinned column (the primary) is
 * capped with `min(32rem, 50vw - rightPinnedWidth)` so it never covers the right-pinned
 * actions on a phone; `rightPinnedWidth` is the sum of the right-pinned columns' `size`.
 */
function pinnedCellProps<TData>(
    column: Column<TData, unknown>,
    rightPinnedWidth: number,
): {
    style?: CSSProperties;
    'data-pinned'?: 'left' | 'right';
    'data-pinned-edge'?: '';
} {
    const side = column.getIsPinned();

    if (side === false) {
        return {};
    }

    const isEdge =
        side === 'left'
            ? column.getIsLastColumn('left')
            : column.getIsFirstColumn('right');
    const size = column.getSize();
    const style: CSSProperties =
        side === 'left'
            ? { left: column.getStart('left') }
            : { right: column.getAfter('right') };

    if (side === 'left' && !isEdge) {
        style.width = size;
        style.minWidth = size;
        style.maxWidth = size;
    }

    if (side === 'left' && isEdge) {
        // Leave room for the right-pinned actions; 50vw alone overlaps them on a phone.
        style.maxWidth = `min(32rem, max(0px, calc(50vw - ${rightPinnedWidth}px)))`;
    }

    if (side === 'right' && !isEdge) {
        style.width = size;
        style.minWidth = size;
        style.maxWidth = size;
    }

    return {
        style,
        'data-pinned': side,
        'data-pinned-edge': isEdge ? '' : undefined,
    };
}

/**
 * Server-driven data table: the shadcn data-table pattern on TanStack Table,
 * with sorting, pagination and filtering done by the server (`manual*`). The
 * page owns the query (Inertia visits, URL params, `per_page`); the table
 * renders the rows, reports header sort and selection changes, and puts the
 * shared pagination footer under the rows inside a `TablePanel`.
 *
 * Generic on purpose: no app types here, so it can become a registry item.
 *
 * @param props - See {@link DataTableProps}.
 * @returns {JSX.Element}
 */
export function DataTable<TData>({
    columns,
    data,
    getRowId,
    sort,
    onSortChange,
    rowSelection,
    onRowSelectionChange,
    pagination,
    onRowClick,
    getRowProps,
    renderExpandedRow,
    emptyState,
    columnPinning,
    className,
}: DataTableProps<TData>) {
    const { t } = useTranslation();
    const rowActionHintId = useId();
    const spacePressedRow = useRef<HTMLTableRowElement>(null);
    const sorting: SortingState = sort
        ? [{ id: sort.id, desc: sort.direction === 'desc' }]
        : [];

    const handleSortingChange: OnChangeFn<SortingState> = (updater) => {
        const nextSorting =
            typeof updater === 'function' ? updater(sorting) : updater;
        const [nextSort] = nextSorting;

        if (!nextSort || !onSortChange) {
            return;
        }

        onSortChange({
            id: nextSort.id,
            direction: nextSort.desc ? 'desc' : 'asc',
        });
    };

    // TanStack Table returns fresh functions every render, so the React
    // Compiler skips this component; that is the documented trade-off.
    // eslint-disable-next-line react-hooks/incompatible-library
    const table = useReactTable({
        data,
        columns,
        getRowId,
        getCoreRowModel: getCoreRowModel(),
        manualSorting: true,
        manualPagination: true,
        manualFiltering: true,
        enableSortingRemoval: false,
        enableRowSelection: rowSelection !== undefined,
        getRowCanExpand: () => renderExpandedRow !== undefined,
        defaultColumn: { enableSorting: false },
        pageCount: pagination?.last_page ?? 1,
        state: {
            sorting,
            rowSelection: rowSelection ?? {},
            columnPinning: columnPinning ?? {},
        },
        onSortingChange: handleSortingChange,
        onRowSelectionChange,
    });

    const rightPinnedWidth = table
        .getRightLeafColumns()
        .reduce((total, pinnedColumn) => total + pinnedColumn.getSize(), 0);

    return (
        <TablePanel
            className={className}
            footer={
                pagination && hasTablePaginationFooter(pagination) ? (
                    <TablePaginationFooter pagination={pagination} />
                ) : undefined
            }
        >
            {onRowClick ? (
                <span id={rowActionHintId} hidden>
                    {t('a11y.openTableRow', {
                        defaultValue: 'Press Enter or Space to open this row.',
                    })}
                </span>
            ) : null}
            <Table>
                <TableHeader>
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow key={headerGroup.id}>
                            {headerGroup.headers.map((header) => (
                                <TableHead
                                    key={header.id}
                                    colSpan={header.colSpan}
                                    className={
                                        header.column.columnDef.meta
                                            ?.headerClassName
                                    }
                                    {...pinnedCellProps(
                                        header.column,
                                        rightPinnedWidth,
                                    )}
                                >
                                    {header.isPlaceholder
                                        ? null
                                        : flexRender(
                                              header.column.columnDef.header,
                                              header.getContext(),
                                          )}
                                </TableHead>
                            ))}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {table.getRowModel().rows.length > 0 ? (
                        table.getRowModel().rows.map((row) => {
                            const rowProps = getRowProps?.(row.original);
                            const openRow = onRowClick
                                ? () => onRowClick(row.original)
                                : undefined;

                            function handleRowKey(
                                event: KeyboardEvent<HTMLTableRowElement>,
                            ): void {
                                const isKeyDown = event.type === 'keydown';
                                const handleConsumerKey = isKeyDown
                                    ? rowProps?.onKeyDown
                                    : rowProps?.onKeyUp;
                                handleConsumerKey?.(event);

                                const isSpaceRelease =
                                    !isKeyDown && event.key === ' ';
                                const hasSpacePress =
                                    spacePressedRow.current ===
                                    event.currentTarget;

                                if (isSpaceRelease && hasSpacePress) {
                                    spacePressedRow.current = null;
                                }

                                if (
                                    event.defaultPrevented ||
                                    event.target !== event.currentTarget
                                ) {
                                    return;
                                }

                                // Stop Space from scrolling on press and activate on release.
                                if (isKeyDown && event.key === ' ') {
                                    event.preventDefault();

                                    if (!event.repeat) {
                                        spacePressedRow.current =
                                            event.currentTarget;
                                    }
                                }

                                if (
                                    (isKeyDown &&
                                        event.key === 'Enter' &&
                                        !event.repeat) ||
                                    (isSpaceRelease && hasSpacePress)
                                ) {
                                    openRow?.();
                                }
                            }

                            function handleRowBlur(
                                event: FocusEvent<HTMLTableRowElement>,
                            ): void {
                                if (
                                    spacePressedRow.current ===
                                    event.currentTarget
                                ) {
                                    spacePressedRow.current = null;
                                }

                                rowProps?.onBlur?.(event);
                            }

                            return (
                                <Fragment key={row.id}>
                                    <TableRow
                                        data-state={
                                            row.getIsSelected()
                                                ? 'selected'
                                                : undefined
                                        }
                                        tabIndex={openRow ? 0 : undefined}
                                        aria-keyshortcuts={
                                            openRow ? 'Enter Space' : undefined
                                        }
                                        {...rowProps}
                                        aria-describedby={
                                            openRow
                                                ? [
                                                      rowProps?.[
                                                          'aria-describedby'
                                                      ],
                                                      rowActionHintId,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' ')
                                                : rowProps?.['aria-describedby']
                                        }
                                        className={cn(
                                            'group/row',
                                            openRow &&
                                                'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
                                            rowProps?.className,
                                        )}
                                        onClick={openRow}
                                        onKeyDown={
                                            openRow
                                                ? handleRowKey
                                                : rowProps?.onKeyDown
                                        }
                                        onKeyUp={
                                            openRow
                                                ? handleRowKey
                                                : rowProps?.onKeyUp
                                        }
                                        onBlur={
                                            openRow
                                                ? handleRowBlur
                                                : rowProps?.onBlur
                                        }
                                    >
                                        {row.getVisibleCells().map((cell) => {
                                            const meta =
                                                cell.column.columnDef.meta;

                                            return (
                                                <TableCell
                                                    key={cell.id}
                                                    // h-10 is a minimum for table cells: text-only rows
                                                    // reach the shared 41px row (40 + border), like rows
                                                    // with a 24px avatar or icon-sm control.
                                                    className={cn(
                                                        'h-10',
                                                        meta?.cellClassName,
                                                    )}
                                                    onClick={
                                                        meta?.isRowClickIgnored
                                                            ? (event) =>
                                                                  event.stopPropagation()
                                                            : undefined
                                                    }
                                                    {...pinnedCellProps(
                                                        cell.column,
                                                        rightPinnedWidth,
                                                    )}
                                                >
                                                    {flexRender(
                                                        cell.column.columnDef
                                                            .cell,
                                                        cell.getContext(),
                                                    )}
                                                </TableCell>
                                            );
                                        })}
                                    </TableRow>
                                    {row.getIsExpanded() &&
                                    renderExpandedRow ? (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={
                                                    row.getVisibleCells().length
                                                }
                                            >
                                                {renderExpandedRow(
                                                    row.original,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </Fragment>
                            );
                        })
                    ) : (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={columns.length}>
                                {emptyState ?? (
                                    <Empty className="p-6 md:p-6">
                                        <EmptyHeader>
                                            <EmptyTitle className="text-sm font-normal text-muted-foreground">
                                                {t('common.noResults')}
                                            </EmptyTitle>
                                        </EmptyHeader>
                                    </Empty>
                                )}
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </TablePanel>
    );
}

/**
 * A 24px hit area around the 16px checkbox (WCAG 2.5.8 target size): the
 * root grows to `size-6` and draws the visible box as an inset `before:`
 * layer, so the checkbox looks the same. Pass as the Checkbox `className`.
 */
export const checkboxTargetClassName =
    'relative isolate block size-6 border-0 bg-transparent shadow-none data-[state=checked]:bg-transparent data-[state=indeterminate]:bg-transparent focus-visible:ring-0 focus-visible:ring-offset-0 before:absolute before:inset-1 before:-z-10 before:rounded-[4px] before:border before:border-input before:shadow-xs data-[state=checked]:before:border-primary data-[state=checked]:before:bg-primary data-[state=indeterminate]:before:border-primary data-[state=indeterminate]:before:bg-primary focus-visible:before:border-ring focus-visible:before:ring-2 focus-visible:before:ring-ring focus-visible:before:ring-offset-2 focus-visible:before:ring-offset-background';

/**
 * The selection column of the shadcn data-table pattern: a select-all
 * checkbox in the header and one checkbox per row, each with a 24px hit
 * area ({@link checkboxTargetClassName}). Clicks on it never open the row.
 *
 * @param options.selectAllLabel - Accessible name of the header checkbox.
 * @param options.getRowLabel - Accessible name of a row checkbox.
 * @returns {ColumnDef<TData, unknown>}
 */
export function dataTableSelectColumn<TData>({
    selectAllLabel,
    getRowLabel,
}: {
    selectAllLabel: string;
    getRowLabel: (row: TData) => string;
}): ColumnDef<TData, unknown> {
    return {
        id: 'select',
        // 40px: the 24px hit area plus the cell padding; `size` matches it for column pinning,
        // and pinned cells render at exactly this width (see {@link pinnedCellProps}).
        size: 40,
        meta: {
            headerClassName: 'w-10',
            cellClassName: 'w-10',
            isRowClickIgnored: true,
        },
        header: ({ table }) => (
            <Checkbox
                checked={
                    table.getIsAllPageRowsSelected() ||
                    (table.getIsSomePageRowsSelected() && 'indeterminate')
                }
                onCheckedChange={(checked) =>
                    table.toggleAllPageRowsSelected(checked === true)
                }
                aria-label={selectAllLabel}
                className={checkboxTargetClassName}
            />
        ),
        cell: ({ row }) => (
            <Checkbox
                checked={row.getIsSelected()}
                onCheckedChange={(checked) =>
                    row.toggleSelected(checked === true)
                }
                aria-label={getRowLabel(row.original)}
                className={checkboxTargetClassName}
            />
        ),
    };
}

/**
 * Expand toggle column for a {@link DataTable} with `renderExpandedRow`.
 *
 * @param options.expandLabel - Accessible name while collapsed.
 * @param options.collapseLabel - Accessible name while expanded.
 * @returns {ColumnDef<TData, unknown>}
 */
export function dataTableExpandColumn<TData>({
    expandLabel,
    collapseLabel,
}: {
    expandLabel: string;
    collapseLabel: string;
}): ColumnDef<TData, unknown> {
    return {
        id: 'expand',
        size: 32,
        meta: { headerClassName: 'w-8', isRowClickIgnored: true },
        header: () => null,
        cell: ({ row }) => {
            const isExpanded = row.getIsExpanded();

            return (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    className="text-muted-foreground"
                    aria-expanded={isExpanded}
                    aria-label={isExpanded ? collapseLabel : expandLabel}
                    onClick={row.getToggleExpandedHandler()}
                >
                    {isExpanded ? (
                        <ChevronDown aria-hidden="true" />
                    ) : (
                        <ChevronRight aria-hidden="true" />
                    )}
                </Button>
            );
        },
    };
}

/**
 * Row actions in the last column (docs/list-page.md, "Row actions"): ghost icon-only
 * `HeaderIconButton`s at `size="icon-sm"` (24px, WCAG 2.5.8), each with a tooltip and an
 * `aria-label`. They show on row hover and while anything in the row has focus or an open
 * menu, and always on touch screens and in selected rows. Hidden only by opacity, so they
 * stay in the tab order and the accessibility tree.
 *
 * Needs the `group/row` class on the row, which `DataTable` sets; a hand-built table adds
 * it to its `TableRow`.
 *
 * @param props.label - Accessible name of the group: the column name ("Actions").
 */
export function DataTableRowActions({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'ml-auto flex w-fit items-center justify-end gap-0.5 opacity-0 transition-opacity group-focus-within/row:opacity-100 group-hover/row:opacity-100 group-data-[state=selected]/row:opacity-100 has-data-[state=open]:opacity-100 motion-reduce:transition-none pointer-coarse:opacity-100',
                className,
            )}
        >
            {children}
        </div>
    );
}
