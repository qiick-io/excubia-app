import { locationPageUrl } from '@/lib/admin-query-params';

/**
 * Shared by the inbox lists (#276, app-ui optional pattern `inbox-list`): the Exceptions and
 * Findings tabs keep their filters in the URL, so a saved view is a link.
 */

/** "New in 24h": items first seen in the last day. */
const NEW_WINDOW_MILLISECONDS = 24 * 60 * 60 * 1000;

/** The query keys an inbox list owns; a saved view replaces all of them. */
const INBOX_FILTER_KEYS = ['status', 'assigned', 'new', 'exception', 'finding'];

/** Whether an item first seen at `firstSeenAt` falls in the "New in 24h" window. */
export function isFirstSeenWithinLastDay(firstSeenAt: string): boolean {
    return (
        Date.now() - new Date(firstSeenAt).getTime() <= NEW_WINDOW_MILLISECONDS
    );
}

/**
 * The URL of a saved view: the current page with its time range, and only the view's own
 * filters. Opening it is an Inertia visit, so the list starts from those filters.
 */
export function savedViewHref(filters: Record<string, string>): string {
    const url = new URL(
        locationPageUrl() || window.location.href,
        window.location.origin,
    );

    for (const key of INBOX_FILTER_KEYS) {
        url.searchParams.delete(key);
    }

    for (const [key, value] of Object.entries(filters)) {
        url.searchParams.set(key, value);
    }

    return `${url.pathname}${url.search}`;
}
