import { Link, usePage } from '@inertiajs/react';
import { BookOpen, FolderGit2, PanelRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AppLogo from '@/components/app-logo';
import { NotificationsBell } from '@/components/notifications/notifications-bell';
import {
    SidebarEntityFlyout,
    SidebarEntityList,
} from '@/components/sidebar-entity-list';
import type { SidebarEntity } from '@/components/sidebar-entity-list';
import { NavFooter } from '@/components/nav-footer';
import { AppNavUser } from '@/components/app-user-menu';
import { ProjectAvatar } from '@/components/projects/project-avatar';
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
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    PINNED_SIDEBAR_MODULE_IDS,
    useNavModules,
} from '@/hooks/use-nav-modules';
import type { ModuleDef } from '@/hooks/use-nav-modules';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toggleAiAssistant } from '@/lib/ai-open';
import { notificationsSource } from '@/lib/notifications';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import ai from '@/routes/ai';
import projectsRoutes from '@/routes/projects';
import type { NavItem, SidebarProject } from '@/types';

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
    const { isCurrentUrl } = useCurrentUrl();
    const { appVersion, sidebarProjects, notifications } = usePage().props;
    const { state: sidebarState, isMobile } = useSidebar();
    const isIconOnly = sidebarState === 'collapsed' && !isMobile;
    const toSidebarEntity = (project: SidebarProject): SidebarEntity => {
        const href = projectsRoutes.show.url(project.slug);

        return {
            id: project.slug,
            label: project.name,
            href,
            status: project.status,
            leading: (
                <ProjectAvatar
                    slug={project.slug}
                    name={project.name}
                    logoUrl={project.logo_url}
                    size="xs"
                />
            ),
            isActive: isCurrentUrl(href, undefined, true),
        };
    };

    const visibleModules = useNavModules();

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

    const isOnAssistantPage = isCurrentUrl(ai.index.url(), undefined, true);

    const renderModuleButton = (item: ModuleDef) => (
        <SidebarMenuButton
            asChild
            isActive={isCurrentUrl(
                item.id === 'settings' ? '/settings' : item.href,
                undefined,
                item.id === 'assistant' ||
                    item.id === 'projects' ||
                    item.id === 'fleet' ||
                    item.id === 'settings',
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
    );

    const projectEntities = (item: ModuleDef) => ({
        pinned: sidebarProjects.pinned.map(toSidebarEntity),
        needsAttention: sidebarProjects.needsAttention.map(toSidebarEntity),
        viewAllHref: toUrl(item.href),
    });

    const renderModuleItem = (item: ModuleDef) => (
        <SidebarMenuItem key={item.id}>
            {item.id === 'projects' ? (
                // Pinned and needs-attention projects (#273): under the item when the sidebar
                // is open, as a menu from the item in the icon-only sidebar.
                <SidebarEntityFlyout
                    label={t(item.titleKey)}
                    {...projectEntities(item)}
                >
                    {isIconOnly ? (
                        <SidebarMenuButton
                            isActive={isCurrentUrl(item.href, undefined, true)}
                        >
                            <item.icon />
                            <span>{t(item.titleKey)}</span>
                        </SidebarMenuButton>
                    ) : (
                        renderModuleButton(item)
                    )}
                </SidebarEntityFlyout>
            ) : (
                renderModuleButton(item)
            )}
            {item.id === 'projects' && !isIconOnly ? (
                <SidebarEntityList {...projectEntities(item)} />
            ) : null}
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
                    {/* The notification centre (#296): the badge comes from the shared prop, so
                        it updates on navigation; Excubia has no broadcasting for live updates. */}
                    <NotificationsBell
                        source={notificationsSource}
                        unreadCount={notifications.unread_count}
                    />
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
                            {`v${appVersion}`}
                        </p>
                    ) : null}
                </SidebarFooter>
            </nav>
        </Sidebar>
    );
}
