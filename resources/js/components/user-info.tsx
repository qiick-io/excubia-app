import { getInitials } from '@/components/admin/user-avatar';
import { ConnectionDot } from '@/components/realtime/connection-dot';
import type { ConnectionState } from '@/components/realtime/connection-dot';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { avatarToneClass } from '@/lib/avatar-tone';
import { cn } from '@/lib/utils';

/** The signed-in user, as the sidebar user menu shows it. */
export type NavUserData = {
    id: number | string;
    /** Display name (externa-core: `formatUserDisplayName(first, last)`). */
    name: string;
    email: string;
    avatar?: string | null;
};

/**
 * Avatar plus display name (and optional email) for the signed-in user. With
 * `connectionState`, the realtime connection badge sits bottom-right.
 */
export function UserInfo({
    user,
    showEmail = false,
    connectionState,
}: {
    user: NavUserData;
    showEmail?: boolean;
    connectionState?: ConnectionState;
}) {
    return (
        <>
            <Avatar>
                {user.avatar ? (
                    <AvatarImage src={user.avatar} alt={user.name} />
                ) : null}
                <AvatarFallback
                    className={cn(
                        avatarToneClass(user.id),
                        'text-xs font-medium',
                    )}
                >
                    {getInitials(user.name, user.email)}
                </AvatarFallback>
                {connectionState ? (
                    <ConnectionDot
                        state={connectionState}
                        className="ring-sidebar"
                    />
                ) : null}
            </Avatar>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <span className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
