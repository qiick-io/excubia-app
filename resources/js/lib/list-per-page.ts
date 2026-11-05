/** "Rows per page" choices for admin lists (antd set; mirrors ValidatesListPerPageTrait). */
export const LIST_PER_PAGE_OPTIONS = [10, 20, 50, 100] as const;

/** Server default page size when `per_page` is absent or unsupported. */
export const DEFAULT_LIST_PER_PAGE = 20;

/**
 * `per_page` query value to keep when a list rebuilds its URL (search, sort,
 * filters): the current size, or `undefined` for the default so URLs stay clean.
 *
 * @param perPage - Current page size from the paginator props
 * @returns Value for the `per_page` query param
 */
export function listPerPageQuery(
    perPage: number | undefined,
): number | undefined {
    return perPage && perPage !== DEFAULT_LIST_PER_PAGE ? perPage : undefined;
}

/**
 * Current URL with a new `per_page` (dropped when default) and `page` reset to 1.
 * Every other query param (search, sort, filters, trashed) is kept.
 *
 * @param currentUrl - Absolute or relative URL of the list page
 * @param perPage - Selected page size
 * @returns Relative URL (path + query) to visit
 */
export function listUrlWithPerPage(
    currentUrl: string,
    perPage: number,
): string {
    const url = new URL(currentUrl, window.location.origin);
    url.searchParams.delete('page');

    if (perPage === DEFAULT_LIST_PER_PAGE) {
        url.searchParams.delete('per_page');
    } else {
        url.searchParams.set('per_page', String(perPage));
    }

    return `${url.pathname}${url.search}`;
}
