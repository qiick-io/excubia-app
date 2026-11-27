import { usePage } from '@inertiajs/react';
import {
    FolderKanban,
    FolderTree,
    Layers,
    LayoutGrid,
    ScrollText,
    Settings,
    Sparkles,
    Users,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import activityLogs from '@/routes/activity-logs';
import ai from '@/routes/ai';
import fleet from '@/routes/fleet';
import groups from '@/routes/groups';
import projectGroups from '@/routes/project-groups';
import projects from '@/routes/projects';
import { edit as editProfile } from '@/routes/profile';
import users from '@/routes/users';
import type { NavItem } from '@/types';

export type ModuleDef = {
    id: string;
    titleKey: string;
    href: NavItem['href'];
    icon: LucideIcon;
    permission?: PermissionEnum;
    accent?: boolean;
};

/** Always first in the nav, regardless of persisted module order. */
export const PINNED_SIDEBAR_MODULE_IDS = ['assistant'] as const;

/**
 * The app's modules the current user sees, in the order the sidebar shows them: enabled in
 * Settings → Appearance → Sidebar, allowed by their permission, the Assistant only when AI is
 * on, and the pinned ones first. The sidebar and the command palette (#274) both read it, so
 * the palette never offers a page the nav hides.
 */
export function useNavModules(): ModuleDef[] {
    const { can } = useCan();
    const { projectSettings, isAssistantEnabled } = usePage().props;

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
                permission: PermissionEnum.CanShowProjects,
            },
            'project-groups': {
                id: 'project-groups',
                titleKey: 'nav.projectGroups',
                href: projectGroups.index.url(),
                icon: FolderTree,
                permission: PermissionEnum.CanShowProjectGroups,
            },
            fleet: {
                id: 'fleet',
                titleKey: 'nav.fleet',
                href: fleet.show.url(),
                icon: Layers,
                permission: PermissionEnum.CanShowProjects,
            },
            assistant: {
                id: 'assistant',
                titleKey: 'nav.assistant',
                href: ai.index.url(),
                icon: Sparkles,
                permission: PermissionEnum.CanUseAi,
                accent: true,
            },
            activity: {
                id: 'activity',
                titleKey: 'nav.activityLog',
                href: activityLogs.index.url(),
                icon: ScrollText,
                permission: PermissionEnum.CanShowActivityLogs,
            },
            users: {
                id: 'users',
                titleKey: 'nav.users',
                href: users.index.url(),
                icon: Users,
                permission: PermissionEnum.CanShowUsers,
            },
            groups: {
                id: 'groups',
                titleKey: 'nav.groups',
                href: groups.index.url(),
                icon: UsersRound,
                permission: PermissionEnum.CanShowGroups,
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

    return visibleModules;
}

function pinModulesFirst<T extends { id: string }>(items: T[]): T[] {
    const pinnedIds = new Set<string>(PINNED_SIDEBAR_MODULE_IDS);
    const pinned = PINNED_SIDEBAR_MODULE_IDS.map((id) =>
        items.find((item) => item.id === id),
    ).filter((item): item is T => !!item);

    return [...pinned, ...items.filter((item) => !pinnedIds.has(item.id))];
}
