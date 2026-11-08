import { useTranslation } from 'react-i18next';
import {
    Avatar,
    AvatarBadge,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { avatarToneClass } from '@/lib/avatar-tone';
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
    /** Presence: green online / grey offline badge. Omit for no badge. */
    isOnline?: boolean;
    size?: 'default' | 'sm' | 'lg';
    className?: string;
};

/**
 * User avatar: the image, else per-user colour initials, with an optional
 * presence `AvatarBadge` (bottom-right). Decorative: the row text next to it
 * already names the user, so the badge is `aria-hidden` and only titled for
 * pointer users. Works as a child of `AvatarGroup`.
 */
export function UserAvatar({
    userId,
    name,
    email = '',
    imageUrl,
    isOnline,
    size = 'default',
    className,
}: UserAvatarProps) {
    const { t } = useTranslation();

    return (
        <Avatar size={size} className={className}>
            {imageUrl ? <AvatarImage src={imageUrl} alt={name} /> : null}
            <AvatarFallback
                className={cn(avatarToneClass(userId), 'text-xs font-medium')}
            >
                {getInitials(name, email)}
            </AvatarFallback>
            {isOnline === undefined ? null : (
                <AvatarBadge
                    className={isOnline ? 'bg-success' : 'bg-muted-foreground'}
                    title={isOnline ? t('common.online') : t('common.offline')}
                    data-test="user-online-dot"
                    data-online={isOnline ? '1' : '0'}
                    aria-hidden
                />
            )}
        </Avatar>
    );
}
