/**
 * Bar scaling for the `bar` variant of `TableCellDetails` (optional pattern
 * `table-cell-details`). No imports, so `node --test` can load it directly.
 */

/**
 * The narrowest bar a value above zero gets, in percent: a value hundreds of
 * times smaller than the largest one still shows that it is not zero.
 */
export const MINIMUM_VISIBLE_BAR_PERCENT = 2;

/**
 * Width of one bar as a percentage of the largest value in the breakdown, so
 * the largest item fills the row and the others compare against it.
 *
 * Zero, negative and non-finite values (and a breakdown whose largest value is
 * not above zero) get no bar; their visible value label still says what they
 * are.
 *
 * @param value - The item's value
 * @param largestValue - The largest value among the items shown
 * @returns A width between 0 and 100
 */
export function barWidthPercent(value: number, largestValue: number): number {
    if (
        !Number.isFinite(value) ||
        !Number.isFinite(largestValue) ||
        value <= 0 ||
        largestValue <= 0
    ) {
        return 0;
    }

    const percent = Math.min(100, (value / largestValue) * 100);

    return Math.max(MINIMUM_VISIBLE_BAR_PERCENT, percent);
}
