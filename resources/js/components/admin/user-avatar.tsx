import { useTranslation } from 'react-i18next';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

/**
 * Two-letter initials from a display name (first + last word), one letter for
 * a single word, else the first letter of `fallback` (an email, say).
 */
export function getInitials(name: string, fallback = ''): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return fallback.trim().charAt(0).toUpperCase();
    }

    const first = words[0].charAt(0);
    const last = words.length > 1 ? words[words.length - 1].charAt(0) : '';

    return `${first}${last}`.toUpperCase();
}

type UserAvatarProps = {
    /** Stable id: picks the user's avatar swatch. */
    userId: number | string;
    /** Display name, for the initials and the image alt text. */
    name: string;
    /** Used for the initial when the name is empty. */
    email?: string;
    imageUrl?: string | null;
    /** Presence: online / offline dot bottom-right. Omit for no dot. */
    isOnline?: boolean;
    className?: string;
};

/**
 * User avatar: per-user colour initials (or the image) with an optional online
 * / offline dot. Decorative: the row text next to it already names the user,
 * so the dot is `aria-hidden` and only titled for pointer users.
 */
export function UserAvatar({
    userId,
    name,
    email = '',
    imageUrl,
    isOnline,
    className,
}: UserAvatarProps) {
    const { t } = useTranslation();

    return (
        <span className={cn('relative shrink-0', className)}>
            <Avatar userId={userId} className="size-7">
                {imageUrl ? <AvatarImage src={imageUrl} alt={name} /> : null}
                <AvatarFallback className="text-xs font-medium">
                    {getInitials(name, email)}
                </AvatarFallback>
            </Avatar>
            {isOnline === undefined ? null : (
                <span
                    className={cn(
                        'absolute -right-0.5 -bottom-0.5 size-2 rounded-full ring-2 ring-background',
                        isOnline ? 'bg-success' : 'bg-muted-foreground/30',
                    )}
                    title={isOnline ? t('common.online') : t('common.offline')}
                    data-test="user-online-dot"
                    data-online={isOnline ? '1' : '0'}
                    aria-hidden
                />
            )}
        </span>
    );
}
