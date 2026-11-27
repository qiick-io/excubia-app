import { CircleCheck, Info, TriangleAlert } from 'lucide-react';
import i18n from '@/lib/i18n';
import {
    createNotificationsSource,
    defaultNotificationItem,
} from '@/lib/notifications-api';
import type {
    DatabaseNotificationRow,
    NotificationItem,
} from '@/lib/notifications-api';
import notifications from '@/routes/notifications';

/** The `toArray()` of `App\Notifications\AlertNotification` (#296). */
type AlertNotificationData = {
    type: 'alert';
    event: string;
    severity: string;
    project: { name: string; slug: string } | null;
    count: number;
    events: string[];
    body: string;
    url: string;
};

/** Events that tell the user something got better, shown with a check instead of a warning. */
const GOOD_NEWS_EVENTS = new Set([
    'finding.resolved',
    'health.recovered',
    'project.recovered',
    'project.reporting_again',
]);

function isAlertNotification(
    data: Record<string, unknown>,
): data is AlertNotificationData {
    return data.type === 'alert';
}

/**
 * The drawer title of an alert notification: "30 new findings in Billing API" for a report
 * push that only raised new findings, "5 alerts in Billing API" for another group, else the
 * event and the project, as the alert history and the alert mail show them.
 */
function alertTitle(alert: AlertNotificationData): string {
    const project = alert.project?.name ?? '';

    if (alert.count > 1) {
        const isOnlyNewFindings =
            alert.events.length === 1 && alert.events[0] === 'finding.new';

        return i18n.t(
            isOnlyNewFindings
                ? 'notifications.alert.newFindings'
                : 'notifications.alert.grouped',
            { count: alert.count, project },
        );
    }

    return project === ''
        ? alert.event
        : i18n.t('notifications.alert.single', {
              event: alert.event,
              project,
          });
}

/**
 * Maps a stored notification to the drawer's item. Alerts get a status icon, a localised title,
 * their summary and the link to where the user acts (the finding, the findings tab or the
 * project); any other type falls back to its `title`, `body` and `url`.
 */
function toNotificationItem(row: DatabaseNotificationRow): NotificationItem {
    const { data } = row;

    if (!isAlertNotification(data)) {
        return defaultNotificationItem(row);
    }

    const isGoodNews = data.count === 1 && GOOD_NEWS_EVENTS.has(data.event);
    const isSevere = data.severity === 'critical' || data.severity === 'high';

    return {
        id: row.id,
        icon: isGoodNews ? CircleCheck : isSevere ? TriangleAlert : Info,
        title: alertTitle(data),
        body: data.body,
        link: data.url,
        time: row.created_at,
        isRead: row.read_at !== null,
    };
}

/** The notification centre's server calls, created once for the sidebar bell. */
export const notificationsSource = createNotificationsSource(
    {
        index: notifications.index(),
        unreadCount: notifications.unreadCount(),
        read: notifications.read(),
        unread: notifications.unread(),
    },
    toNotificationItem,
);
