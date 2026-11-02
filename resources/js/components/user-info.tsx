import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

/**
 * Avatar plus display name (and optional email) for the authenticated user.
 * @param {{ user: User, showEmail?: boolean }} props
 */
export function UserInfo({
    user,
    showEmail = false,
}: {
    user: User;
    showEmail?: boolean;
}) {
    const getInitials = useInitials();
    const displayName = user.name;

    return (
        <>
            <span className="relative shrink-0">
                <Avatar
                    userId={user.id}
                    className="h-8 w-8 overflow-hidden rounded-full"
                >
                    <AvatarImage src={user.avatar} alt={displayName} />
                    <AvatarFallback className="rounded-lg text-xs font-medium">
                        {getInitials(user.name)}
                    </AvatarFallback>
                </Avatar>
            </span>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-medium">{displayName}</span>
                {showEmail && (
                    <span className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
