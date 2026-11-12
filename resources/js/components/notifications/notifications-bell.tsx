import { Bell } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { NotificationsDrawer } from '@/components/notifications/notifications-drawer';
import { SidebarUnreadBadge } from '@/components/sidebar-unread-badge';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { announce } from '@/lib/live-announcer';
import {
    attachNotificationSoundUnlockListeners,
    getNotificationSoundPreferences,
    playNotificationSound,
    setNotificationSoundPreferences,
    unlockNotificationSound,
} from '@/lib/notification-sound';
import { NOTIFICATIONS_UPDATED_EVENT } from '@/lib/notifications-api';
import type { NotificationsSource } from '@/lib/notifications-api';

const UNREAD_ANNOUNCE_DEBOUNCE_MS = 800;

type NotificationsBellProps = {
    /** The server calls (`createNotificationsSource(...)` for the Laravel contract). */
    source: NotificationsSource;
    /**
     * The unread count from the shared Inertia prop. Every visit refreshes it,
     * so without realtime the badge updates on navigation.
     */
    unreadCount: number;
    /**
     * Optional realtime: subscribes to "a notification was created" for the
     * signed-in user and returns the unsubscribe. Pass a stable function
     * (`useCallback`). Without it, there are no live updates.
     */
    subscribe?: (onNotificationCreated: () => void) => () => void;
    /** The user's sound preference; off by default. */
    isSoundEnabled?: boolean;
};

/**
 * Sidebar bell with the unread badge, opening the notifications drawer on the
 * right. Unread rises are announced politely to screen readers (debounced, so a
 * burst is one announcement) and, with the sound preference on and the drawer
 * closed, a live notification chimes.
 */
export function NotificationsBell({
    source,
    unreadCount: sharedUnreadCount,
    subscribe,
    isSoundEnabled = false,
}: NotificationsBellProps) {
    const { t } = useTranslation();
    const [unreadCount, setUnreadCount] = useState(sharedUnreadCount);
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [reloadToken, setReloadToken] = useState(0);
    const isDrawerOpenRef = useRef(isDrawerOpen);
    const previousUnreadCountRef = useRef(sharedUnreadCount);
    const announceTimeoutRef = useRef<number | undefined>(undefined);

    useEffect(() => {
        isDrawerOpenRef.current = isDrawerOpen;
    }, [isDrawerOpen]);

    const [previousSharedUnreadCount, setPreviousSharedUnreadCount] =
        useState(sharedUnreadCount);

    // Adopt a new server count during render (React's "adjust state on prop change" pattern).
    if (previousSharedUnreadCount !== sharedUnreadCount) {
        setPreviousSharedUnreadCount(sharedUnreadCount);
        setUnreadCount(sharedUnreadCount);
    }

    const refreshUnreadCount = useCallback(() => {
        void source
            .fetchUnreadCount()
            .then(setUnreadCount)
            .catch(() => {
                // The next visit or event brings the count back.
            });
    }, [source]);

    useEffect(() => {
        const previousCount = previousUnreadCountRef.current;
        previousUnreadCountRef.current = unreadCount;

        if (unreadCount <= previousCount || isDrawerOpenRef.current) {
            return;
        }

        if (announceTimeoutRef.current) {
            window.clearTimeout(announceTimeoutRef.current);
        }

        // Debounce realtime bursts so VoiceOver hears one polite update.
        announceTimeoutRef.current = window.setTimeout(() => {
            announce(t('a11y.notificationsUnread', { count: unreadCount }), {
                politeness: 'polite',
            });
        }, UNREAD_ANNOUNCE_DEBOUNCE_MS);

        return () => {
            if (announceTimeoutRef.current) {
                window.clearTimeout(announceTimeoutRef.current);
            }
        };
    }, [unreadCount, t]);

    useEffect(() => {
        window.addEventListener(
            NOTIFICATIONS_UPDATED_EVENT,
            refreshUnreadCount,
        );

        const unsubscribe = subscribe?.(() => {
            refreshUnreadCount();
            setReloadToken((token) => token + 1);

            if (!isDrawerOpenRef.current) {
                playNotificationSound();
            }
        });

        return () => {
            window.removeEventListener(
                NOTIFICATIONS_UPDATED_EVENT,
                refreshUnreadCount,
            );
            unsubscribe?.();
        };
    }, [subscribe, refreshUnreadCount]);

    useEffect(() => {
        setNotificationSoundPreferences({
            ...getNotificationSoundPreferences(),
            isNotificationSoundEnabled: isSoundEnabled,
        });

        if (!isSoundEnabled) {
            return;
        }

        // Turning the preference on is a gesture opportunity: unlock early.
        unlockNotificationSound();

        return attachNotificationSoundUnlockListeners();
    }, [isSoundEnabled]);

    const handleUnreadCountChange = useCallback((count: number) => {
        setUnreadCount(count);
    }, []);

    const notificationsLabel =
        unreadCount > 0
            ? t('a11y.notificationsUnread', { count: unreadCount })
            : t('a11y.notifications');

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        type="button"
                        tooltip={{ children: t('a11y.notifications') }}
                        onClick={() => setIsDrawerOpen(true)}
                        data-test="notifications-bell"
                        aria-label={notificationsLabel}
                        aria-expanded={isDrawerOpen}
                        aria-haspopup="dialog"
                    >
                        <Bell aria-hidden="true" />
                        <span>{t('a11y.notifications')}</span>
                    </SidebarMenuButton>
                    <SidebarUnreadBadge count={unreadCount} decorative />
                </SidebarMenuItem>
            </SidebarMenu>

            <NotificationsDrawer
                open={isDrawerOpen}
                onOpenChange={setIsDrawerOpen}
                source={source}
                onUnreadCountChange={handleUnreadCountChange}
                reloadToken={reloadToken}
            />
        </>
    );
}
