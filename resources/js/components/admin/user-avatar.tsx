import { useTranslation } from 'react-i18next';
import {
    Avatar,
    AvatarBadge,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { avatarToneClass } from '@/lib/avatar-tone';
import type { RecordId } from '@/lib/record-id';
import { cn } from '@/lib/utils';

const initialsSegmenter = new Intl.Segmenter(undefined, {
    granularity: 'grapheme',
});

function firstGrapheme(word: string): string {
    return initialsSegmenter.segment(word).containing(0)?.segment ?? '';
}

/**
 * The first grapheme of the first and last words of a display name, or the
 * first grapheme of `fallback` when the name is blank. Keep emoji sequences
 * and combining marks intact.
 */
export function getInitials(name: string, fallback = ''): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return firstGrapheme(fallback.trim()).toUpperCase();
    }

    const first = firstGrapheme(words[0]);
    const last = words.length > 1 ? firstGrapheme(words[words.length - 1]) : '';

    return `${first}${last}`.toUpperCase();
}

type UserAvatarProps = {
    /** Stable id: picks the user's avatar swatch. */
    userId: RecordId;
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
