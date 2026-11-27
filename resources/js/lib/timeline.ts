/**
 * Chip folding for `Timeline` (optional pattern `timeline`). No imports, so
 * `node --test` can load it directly.
 */

/**
 * How many chips a step shows before the others fold behind "Show N more":
 * about two lines of package chips at 390px, one line on a desktop.
 */
export const DEFAULT_VISIBLE_CHIP_COUNT = 6;

/**
 * Splits a step's chips into the ones always shown and the ones folded
 * behind the "Show N more" toggle, keeping the app's order.
 *
 * A toggle that would reveal a single chip takes as much room as that chip,
 * so then every chip shows and nothing folds. A count below one still shows
 * one chip, and a non-finite count shows them all.
 *
 * @param chips - The step's chips, in the order the app wants them read
 * @param visibleChipCount - How many chips to show before folding
 * @returns The chips to show and the chips to fold (possibly empty)
 */
export function splitTimelineChips<Chip>(
    chips: readonly Chip[],
    visibleChipCount: number,
): { visibleChips: Chip[]; foldedChips: Chip[] } {
    const shownChipLimit = Number.isFinite(visibleChipCount)
        ? Math.max(1, Math.floor(visibleChipCount))
        : chips.length;

    if (chips.length <= shownChipLimit + 1) {
        return { visibleChips: [...chips], foldedChips: [] };
    }

    return {
        visibleChips: chips.slice(0, shownChipLimit),
        foldedChips: chips.slice(shownChipLimit),
    };
}
