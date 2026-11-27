import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Item,
    ItemContent,
    ItemDescription,
    ItemFooter,
    ItemGroup,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { notifyNotificationsUpdated } from '@/lib/notifications-api';
import type {
    NotificationItem,
    NotificationsSource,
} from '@/lib/notifications-api';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

type NotificationsDrawerProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    source: NotificationsSource;
    onUnreadCountChange: (count: number) => void;
    /** Bumped by the bell when a notification arrives live, to reload the open list. */
    reloadToken?: number;
};

/** The whole row is the link: its `::after` covers the item, actions sit above it. */
const STRETCHED_LINK_CLASS =
    'after:absolute after:inset-0 after:rounded-md focus-visible:outline-none';

/**
 * Right-side drawer listing the user's notifications, newest first. Clicking
 * one marks it read and opens its link; each has "Mark as read" (or "Mark as
 * unread" when the source supports it), and the header has "Mark all as read".
 *
 * ponytail: only the first page (the backend's 20 newest); add "Load more"
 * when people need older notifications here.
 */
export function NotificationsDrawer({
    open,
    onOpenChange,
    source,
    onUnreadCountChange,
    reloadToken = 0,
}: NotificationsDrawerProps) {
    const { t, i18n } = useTranslation();
    const [notifications, setNotifications] = useState<NotificationItem[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [hasLoadFailed, setHasLoadFailed] = useState(false);
    const [isMutating, setIsMutating] = useState(false);

    const hasUnread = notifications.some(
        (notification) => !notification.isRead,
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        let isCancelled = false;

        const load = async (): Promise<void> => {
            setIsLoading(true);
            setHasLoadFailed(false);

            try {
                const items = await source.fetchNotifications();

                if (!isCancelled) {
                    setNotifications(items);
                }
            } catch {
                if (!isCancelled) {
                    setHasLoadFailed(true);
                }
            } finally {
                if (!isCancelled) {
                    setIsLoading(false);
                }
            }
        };

        void load();

        return () => {
            isCancelled = true;
        };
    }, [open, source, reloadToken]);

    /**
     * Runs a read-state change, applies it to the list and reports the server's
     * new unread count; a failure leaves the list as it was and says so.
     */
    const mutate = async (
        request: () => Promise<number>,
        applyToList: (current: NotificationItem[]) => NotificationItem[],
    ): Promise<void> => {
        setIsMutating(true);

        try {
            const unreadCount = await request();
            setNotifications(applyToList);
            onUnreadCountChange(unreadCount);
            notifyNotificationsUpdated();
        } catch {
            toast.error(t('notifications.updateFailed'));
        } finally {
            setIsMutating(false);
        }
    };

    const setReadState =
        (ids: string[] | 'all', isRead: boolean) =>
        (current: NotificationItem[]) =>
            current.map((notification) =>
                ids === 'all' || ids.includes(notification.id)
                    ? { ...notification, isRead }
                    : notification,
            );

    const handleMarkAllRead = (): void => {
        if (isMutating || !hasUnread) {
            return;
        }

        void mutate(() => source.markRead('all'), setReadState('all', true));
    };

    const handleMarkRead = (id: string): void => {
        void mutate(() => source.markRead([id]), setReadState([id], true));
    };

    const handleMarkUnread = (id: string): void => {
        const { markUnread } = source;

        if (!markUnread) {
            return;
        }

        void mutate(() => markUnread([id]), setReadState([id], false));
    };

    /** The click navigates at once; the read request finishes in the background. */
    const handleOpen = (notification: NotificationItem): void => {
        if (!notification.isRead) {
            handleMarkRead(notification.id);
        }

        onOpenChange(false);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>{t('notifications.title')}</SheetTitle>
                    <SheetDescription>
                        {t('notifications.description')}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex items-center justify-end px-4 pb-2">
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-muted-foreground hover:text-foreground"
                        disabled={isMutating || isLoading || !hasUnread}
                        onClick={handleMarkAllRead}
                        data-test="notifications-mark-all-read"
                    >
                        {t('notifications.markAllRead')}
                    </Button>
                </div>

                <ScrollArea className="min-h-0 flex-1">
                    <ItemGroup className="gap-2 px-4 pb-4">
                        {isLoading ? (
                            <div className="flex flex-col items-center gap-2 py-6 text-sm text-muted-foreground">
                                <Spinner className="size-5" />
                                {t('notifications.loading')}
                            </div>
                        ) : null}

                        {!isLoading && hasLoadFailed ? (
                            <p className="text-sm text-muted-foreground">
                                {t('notifications.loadFailed')}
                            </p>
                        ) : null}

                        {!isLoading &&
                        !hasLoadFailed &&
                        notifications.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('notifications.empty')}
                            </p>
                        ) : null}

                        {notifications.map((notification) => {
                            const Icon = notification.icon;
                            const title =
                                notification.title ||
                                t('notifications.untitled');
                            const isUnread = !notification.isRead;

                            return (
                                <Item
                                    key={notification.id}
                                    role="listitem"
                                    variant="outline"
                                    size="sm"
                                    className={cn(
                                        'relative gap-x-3 gap-y-1 p-3 has-[a:focus-visible]:border-ring has-[a:focus-visible]:ring-[3px] has-[a:focus-visible]:ring-ring/50',
                                        notification.link &&
                                            'hover:bg-accent/50',
                                        isUnread && 'bg-muted/40',
                                    )}
                                    data-unread={isUnread || undefined}
                                    data-test="notifications-item"
                                >
                                    {Icon ? (
                                        <ItemMedia variant="icon">
                                            <Icon aria-hidden="true" />
                                        </ItemMedia>
                                    ) : null}
                                    <ItemContent>
                                        <ItemTitle>
                                            {notification.link ? (
                                                notification.isDocumentLink ? (
                                                    <a
                                                        href={notification.link}
                                                        className={
                                                            STRETCHED_LINK_CLASS
                                                        }
                                                        onClick={() =>
                                                            handleOpen(
                                                                notification,
                                                            )
                                                        }
                                                    >
                                                        {title}
                                                    </a>
                                                ) : (
                                                    <Link
                                                        href={notification.link}
                                                        className={
                                                            STRETCHED_LINK_CLASS
                                                        }
                                                        onClick={() =>
                                                            handleOpen(
                                                                notification,
                                                            )
                                                        }
                                                    >
                                                        {title}
                                                    </Link>
                                                )
                                            ) : (
                                                title
                                            )}
                                        </ItemTitle>
                                        {notification.body ? (
                                            <ItemDescription className="line-clamp-none">
                                                {notification.body}
                                            </ItemDescription>
                                        ) : null}
                                        <time
                                            dateTime={notification.time}
                                            className="mt-1 text-xs text-muted-foreground"
                                        >
                                            {new Date(
                                                notification.time,
                                            ).toLocaleString(i18n.language)}
                                        </time>
                                    </ItemContent>
                                    <ItemFooter className="relative z-10 flex-wrap justify-start gap-x-3 gap-y-1">
                                        {isUnread ? (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto px-0 text-muted-foreground hover:text-foreground"
                                                disabled={isMutating}
                                                onClick={() =>
                                                    handleMarkRead(
                                                        notification.id,
                                                    )
                                                }
                                                data-test="notifications-mark-read"
                                            >
                                                {t('notifications.markRead')}
                                            </Button>
                                        ) : null}
                                        {!isUnread && source.markUnread ? (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto px-0 text-muted-foreground hover:text-foreground"
                                                disabled={isMutating}
                                                onClick={() =>
                                                    handleMarkUnread(
                                                        notification.id,
                                                    )
                                                }
                                                data-test="notifications-mark-unread"
                                            >
                                                {t('notifications.markUnread')}
                                            </Button>
                                        ) : null}
                                    </ItemFooter>
                                </Item>
                            );
                        })}
                    </ItemGroup>
                </ScrollArea>
            </SheetContent>
        </Sheet>
    );
}
