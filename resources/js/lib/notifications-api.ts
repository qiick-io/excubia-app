import type { ComponentType } from 'react';

/**
 * The client side of the notification centre (`docs/notifications.md`): the
 * item the drawer renders, the source the bell and drawer call, and a default
 * source for the Laravel backend contract. The app passes its own endpoint
 * URLs (strings or Wayfinder routes), so no route is hard-coded here.
 */

/** Custom event name fired when unread notification counts may have changed. */
export const NOTIFICATIONS_UPDATED_EVENT = 'notifications:updated';

/**
 * Tells the bell to re-read the unread count, for example after an action
 * elsewhere in the app created or read notifications.
 */
export function notifyNotificationsUpdated(): void {
    window.dispatchEvent(new Event(NOTIFICATIONS_UPDATED_EVENT));
}

/** A Laravel database notification row, as the backend contract returns it. */
export type DatabaseNotificationRow = {
    id: string;
    type: string;
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string;
};

/**
 * One notification as the drawer shows it. The app maps its own notification
 * types to this shape (`toItem`): which icon, which title, where it leads.
 */
export type NotificationItem = {
    id: string;
    /** A `lucide-react` icon; none shows no media column. */
    icon?: ComponentType<{ className?: string }>;
    title: string;
    body?: string;
    /** Where clicking the notification goes; none makes it plain text. */
    link?: string | null;
    /** Open the link with a full page load (a download, a non-Inertia URL). */
    isDocumentLink?: boolean;
    /** ISO 8601 creation time. */
    time: string;
    isRead: boolean;
};

/** Everything the bell and the drawer need from the server. */
export type NotificationsSource = {
    /** The newest notifications (the first page). */
    fetchNotifications: () => Promise<NotificationItem[]>;
    fetchUnreadCount: () => Promise<number>;
    /** Marks the ids, or every notification, read; resolves to the new unread count. */
    markRead: (ids: string[] | 'all') => Promise<number>;
    /** Marks the ids unread; without it the drawer offers no "Mark as unread". */
    markUnread?: (ids: string[]) => Promise<number>;
};

/** A URL string or a Wayfinder route (`{ url, method }`). */
type EndpointUrl = string | { url: string };

/** The app's notification routes (externa-core: `/notifications`, …). */
export type NotificationsEndpoints = {
    index: EndpointUrl;
    unreadCount: EndpointUrl;
    read: EndpointUrl;
    unread?: EndpointUrl;
};

function endpointUrl(endpoint: EndpointUrl): string {
    return typeof endpoint === 'string' ? endpoint : endpoint.url;
}

/**
 * Laravel accepts the `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN`; the
 * `csrf-token` meta tag is the fallback for apps without the cookie.
 */
function csrfHeaders(): Record<string, string> {
    const cookieMatch = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    if (cookieMatch) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookieMatch[1]) };
    }

    const metaToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
}

/**
 * Same-origin JSON request.
 *
 * @throws {Error} When the response is not 2xx
 */
async function requestJson<ResponseBody>(
    url: string,
    body?: unknown,
): Promise<ResponseBody> {
    const response = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        // A click on a notification marks it read and navigates at once.
        keepalive: body !== undefined,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    return (await response.json()) as ResponseBody;
}

/**
 * The default mapping: `data.title`, `data.body` and `data.url` of the
 * notification's `toArray()`, no icon. Apps with typed notifications pass
 * their own `toItem` to pick icons and build links.
 */
export function defaultNotificationItem(
    row: DatabaseNotificationRow,
): NotificationItem {
    const { title, body, url } = row.data;

    return {
        id: row.id,
        title: typeof title === 'string' ? title : '',
        body: typeof body === 'string' ? body : undefined,
        link: typeof url === 'string' ? url : null,
        time: row.created_at,
        isRead: row.read_at !== null,
    };
}

/**
 * A {@link NotificationsSource} for the Laravel backend contract: `GET index`
 * (paginated `{ data: rows }`), `GET unreadCount` (`{ count }`), `POST read`
 * (`{ ids }` or `{ all: true }`) and `POST unread` (`{ ids }`), both answering
 * `{ unread_count }`.
 */
export function createNotificationsSource(
    endpoints: NotificationsEndpoints,
    toItem: (
        row: DatabaseNotificationRow,
    ) => NotificationItem = defaultNotificationItem,
): NotificationsSource {
    const unreadEndpoint = endpoints.unread;

    return {
        fetchNotifications: async () => {
            const payload = await requestJson<{
                data: DatabaseNotificationRow[];
            }>(endpointUrl(endpoints.index));

            return payload.data.map(toItem);
        },
        fetchUnreadCount: async () => {
            const payload = await requestJson<{ count: number }>(
                endpointUrl(endpoints.unreadCount),
            );

            return payload.count;
        },
        markRead: async (ids) => {
            const payload = await requestJson<{ unread_count: number }>(
                endpointUrl(endpoints.read),
                ids === 'all' ? { all: true } : { ids },
            );

            return payload.unread_count;
        },
        markUnread: unreadEndpoint
            ? async (ids) => {
                  const payload = await requestJson<{ unread_count: number }>(
                      endpointUrl(unreadEndpoint),
                      { ids },
                  );

                  return payload.unread_count;
              }
            : undefined,
    };
}
