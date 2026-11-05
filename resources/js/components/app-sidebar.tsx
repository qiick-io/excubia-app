import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    FolderGit2,
    FolderKanban,
    LayoutGrid,
    PanelRight,
    ScrollText,
    Settings,
    Sparkles,
    Users,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { AppNavUser } from '@/components/app-user-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toggleAiAssistant } from '@/lib/ai-open';
import { dashboard } from '@/routes';
import activityLogs from '@/routes/activity-logs';
import assistant from '@/routes/assistant';
import groups from '@/routes/groups';
import projects from '@/routes/projects';
import { edit as editProfile } from '@/routes/profile';
import users from '@/routes/users';
import type { NavItem } from '@/types';

type ModuleDef = {
    id: string;
    titleKey: string;
    href: NavItem['href'];
    icon: LucideIcon;
    permission?: PermissionEnum;
    accent?: boolean;
};

/** Always first in the nav, regardless of persisted module order. */
const PINNED_SIDEBAR_MODULE_IDS = ['assistant'] as const;

/** Keyboard shortcut hint for the AI assistant panel (handled in the panel). */
const AI_PANEL_SHORTCUT =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad|iPod/.test(navigator.userAgent)
        ? '⌘J'
        : 'Ctrl+J';

/**
 * Primary application sidebar with navigation and user menu.
 */
export function AppSidebar() {
    const { t } = useTranslation();
    const { can } = useCan();
    const { isCurrentUrl } = useCurrentUrl();
    const { projectSettings, isAssistantEnabled, appVersion } = usePage().props;

    const moduleDefs = useMemo<Record<string, ModuleDef>>(
        () => ({
            dashboard: {
                id: 'dashboard',
                titleKey: 'nav.dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
            projects: {
                id: 'projects',
                titleKey: 'nav.projects',
                href: projects.index.url(),
                icon: FolderKanban,
                permission: PermissionEnum.ProjectsView,
            },
            assistant: {
                id: 'assistant',
                titleKey: 'nav.assistant',
                href: assistant.index.url(),
                icon: Sparkles,
                permission: PermissionEnum.AiUse,
                accent: true,
            },
            activity: {
                id: 'activity',
                titleKey: 'nav.activityLog',
                href: activityLogs.index.url(),
                icon: ScrollText,
                permission: PermissionEnum.ActivityView,
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

    const visibleModules = pinModulesFirst(
        projectSettings.sidebarModules
            .filter((module) => module.enabled)
            .map((module) => moduleDefs[module.id])
            .filter((def): def is ModuleDef => !!def)
            .filter((def) => def.id !== 'assistant' || isAssistantEnabled)
            .filter((def) => !def.permission || can(def.permission)),
    );

    const pinnedIds = new Set<string>(PINNED_SIDEBAR_MODULE_IDS);
    const pinnedModules = visibleModules.filter((item) =>
        pinnedIds.has(item.id),
    );
    const platformModules = visibleModules.filter(
        (item) => !pinnedIds.has(item.id),
    );

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

    const isOnAssistantPage = isCurrentUrl(
        assistant.index.url(),
        undefined,
        true,
    );

    const renderModuleItem = (item: ModuleDef) => (
        <SidebarMenuItem key={item.id}>
            <SidebarMenuButton
                asChild
                isActive={isCurrentUrl(
                    item.id === 'settings' ? '/settings' : item.href,
                    undefined,
                    item.id === 'assistant' || item.id === 'settings',
                )}
                tooltip={{ children: t(item.titleKey) }}
                className={item.accent ? 'sidebar-ai-nav' : undefined}
            >
                <Link href={item.href} prefetch>
                    <item.icon />
                    <span>{t(item.titleKey)}</span>
                    {item.id === 'assistant' && !isOnAssistantPage ? (
                        <kbd className="ml-auto font-sans text-xs text-muted-foreground transition-opacity group-focus-within/menu-item:opacity-0 group-hover/menu-item:opacity-0 group-data-[collapsible=icon]:hidden">
                            {AI_PANEL_SHORTCUT}
                        </kbd>
                    ) : null}
                </Link>
            </SidebarMenuButton>
            {/* The item goes to /assistant; this hover action opens the side panel in place
                (the shortcut hint gives way to it on hover). */}
            {item.id === 'assistant' && !isOnAssistantPage ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <SidebarMenuAction
                            showOnHover
                            onClick={toggleAiAssistant}
                            aria-label={t('assistant.openPanel')}
                            data-test="sidebar-ai-panel"
                        >
                            <PanelRight />
                        </SidebarMenuAction>
                    </TooltipTrigger>
                    <TooltipContent side="right">
                        {t('assistant.openPanel')} ({AI_PANEL_SHORTCUT})
                    </TooltipContent>
                </Tooltip>
            ) : null}
        </SidebarMenuItem>
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/*
              Real <nav> (not role=navigation on a div) so desktop chrome and the
              mobile Sheet both expose a labelled navigation landmark.
            */}
            <nav
                aria-label={t('a11y.mainNav')}
                className="flex h-full w-full flex-col"
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
                    {pinnedModules.length > 0 ? (
                        <SidebarGroup className="px-2 py-0">
                            <SidebarMenu>
                                {pinnedModules.map(renderModuleItem)}
                            </SidebarMenu>
                        </SidebarGroup>
                    ) : null}

                    {platformModules.length > 0 ? (
                        <SidebarGroup className="px-2 py-0">
                            <SidebarGroupLabel>
                                {t('nav.platform')}
                            </SidebarGroupLabel>
                            <SidebarMenu>
                                {platformModules.map(renderModuleItem)}
                            </SidebarMenu>
                        </SidebarGroup>
                    ) : null}
                </SidebarContent>

                <SidebarFooter>
                    <NavFooter items={footerNavItems} className="mt-auto" />
                    <AppNavUser />
                    {appVersion ? (
                        <p className="truncate px-2 text-center text-xs leading-none text-muted-foreground group-data-[collapsible=icon]:hidden">
                            v{appVersion}
                        </p>
                    ) : null}
                </SidebarFooter>
            </nav>
        </Sidebar>
    );
}

function pinModulesFirst<T extends { id: string }>(items: T[]): T[] {
    const pinnedIds = new Set<string>(PINNED_SIDEBAR_MODULE_IDS);
    const pinned = PINNED_SIDEBAR_MODULE_IDS.map((id) =>
        items.find((item) => item.id === id),
    ).filter((item): item is T => !!item);

    return [...pinned, ...items.filter((item) => !pinnedIds.has(item.id))];
}
