import type { ReactNode } from 'react';

/**
 * The plot takes focus for arrow-key navigation, and `ChartContainer` hides the SVG's
 * outline, so the wrapper draws the focus ring.
 */
export const CHART_FOCUS_CLASS =
    'aspect-auto h-64 w-full rounded-md has-[.recharts-surface:focus-visible]:ring-2 has-[.recharts-surface:focus-visible]:ring-ring';

/**
 * Wraps the tooltip in a polite live region, so moving through the plot with the arrow
 * keys reads each point; shadcn's tooltip content is a plain div.
 */
export function AnnouncedTooltip({ children }: { children: ReactNode }) {
    return (
        <div aria-live="polite" aria-atomic="true">
            {children}
        </div>
    );
}

/**
 * The numbers behind a chart as a real table, visually hidden: a screen reader gets
 * every value with its row and column headers instead of an SVG it cannot read.
 * Sighted readers already see the numbers in the chart's legend or labels.
 */
export function ChartDataTable({
    caption,
    columnHeaders,
    rows,
}: {
    /** The chart's title, read as the table caption. */
    caption: string;
    /** The header of every column, the row header column first ("Date", "Cost", …). */
    columnHeaders: string[];
    /** One row per category or date: its header, then the formatted values. */
    rows: { header: string; values: string[] }[];
}) {
    return (
        <table className="sr-only">
            <caption>{caption}</caption>
            <thead>
                <tr>
                    {columnHeaders.map((columnHeader) => (
                        <th key={columnHeader} scope="col">
                            {columnHeader}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr key={row.header}>
                        <th scope="row">{row.header}</th>
                        {row.values.map((value, columnIndex) => (
                            <td
                                key={
                                    columnHeaders[columnIndex + 1] ??
                                    columnIndex
                                }
                            >
                                {value}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
