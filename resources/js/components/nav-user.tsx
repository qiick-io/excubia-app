import { ChevronsUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import type { ConnectionState } from '@/components/realtime/connection-dot';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import type { NavUserData } from '@/components/user-info';
import { useIsMobile } from '@/hooks/use-mobile';

/**
 * Sidebar user menu: avatar (with the realtime connection dot), name and
 * chevron; the dropdown body is `children`, normally {@link UserMenuContent}.
 * Renders nothing without a user.
 */
export function NavUser({
    user,
    connectionState,
    children,
}: {
    user: NavUserData | null | undefined;
    connectionState?: ConnectionState;
    children: ReactNode;
}) {
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    if (!user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                            data-test="sidebar-menu-button"
                        >
                            <UserInfo
                                user={user}
                                connectionState={connectionState}
                            />
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'left'
                                  : 'bottom'
                        }
                    >
                        {children}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
