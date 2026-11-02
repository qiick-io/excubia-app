import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    FolderGit2,
    LayoutGrid,
    Settings,
    Users,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';
import groups from '@/routes/groups';
import { edit as editProfile } from '@/routes/profile';
import users from '@/routes/users';
import type { NavItem, SidebarModuleSetting } from '@/types';

type ModuleDef = {
    id: string;
    titleKey: string;
    href: NavItem['href'];
    icon: LucideIcon;
    permission?: PermissionEnum;
};

/**
 * Primary application sidebar with navigation and user menu.
 */
export function AppSidebar() {
    const { t } = useTranslation();
    const { can } = useCan();
    const { isCurrentUrl } = useCurrentUrl();
    const { projectSettings } = usePage().props;

    const moduleDefs = useMemo<Record<string, ModuleDef>>(
        () => ({
            dashboard: {
                id: 'dashboard',
                titleKey: 'nav.dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
            users: {
                id: 'users',
                titleKey: 'nav.users',
                href: users.index.url(),
                icon: Users,
                permission: PermissionEnum.UsersManage,
            },
            groups: {
                id: 'groups',
                titleKey: 'nav.groups',
                href: groups.index.url(),
                icon: UsersRound,
                permission: PermissionEnum.GroupsManage,
            },
            settings: {
                id: 'settings',
                titleKey: 'nav.settings',
                href: editProfile(),
                icon: Settings,
            },
        }),
        [],
    );

    const modules: SidebarModuleSetting[] =
        projectSettings?.sidebarModules ??
        Object.keys(moduleDefs).map((id) => ({
            id,
            enabled: true,
            locked: id === 'dashboard' || id === 'settings',
        }));

    const visibleModules = modules
        .filter((module) => module.enabled)
        .map((module) => moduleDefs[module.id])
        .filter((def): def is ModuleDef => !!def)
        .filter((def) => !def.permission || can(def.permission));

    const footerNavItems: NavItem[] = [
        {
            title: t('nav.repository'),
            href: 'https://github.com/qiick-io/excubia-app',
            icon: FolderGit2,
        },
        {
            title: t('nav.documentation'),
            href: 'https://github.com/qiick-io/excubia-app#readme',
            icon: BookOpen,
        },
    ];

    const renderModuleItem = (item: ModuleDef) => (
        <SidebarMenuItem key={item.id}>
            <SidebarMenuButton
                asChild
                isActive={isCurrentUrl(
                    item.id === 'settings' ? '/settings' : item.href,
                    undefined,
                    item.id === 'settings',
                )}
                tooltip={{ children: t(item.titleKey) }}
            >
                <Link href={item.href} prefetch className="relative">
                    <item.icon />
                    <span>{t(item.titleKey)}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );

    return (
        <Sidebar
            collapsible="icon"
            variant="inset"
            role="navigation"
            aria-label={t('a11y.mainNav')}
        >
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {visibleModules.length > 0 ? (
                    <SidebarGroup className="px-2 py-0">
                        <SidebarGroupLabel>
                            {t('nav.platform')}
                        </SidebarGroupLabel>
                        <SidebarMenu>
                            {visibleModules.map(renderModuleItem)}
                        </SidebarMenu>
                    </SidebarGroup>
                ) : null}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
