import { router, usePage } from '@inertiajs/react';
import {
    Bug,
    FolderKanban,
    FolderTree,
    MoonStar,
    PanelRight,
    Sun,
    User,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { CommandPalette } from '@/components/command-palette';
import type { CommandPaletteGroup } from '@/components/command-palette';
import { projectAvatarIcon } from '@/components/projects/project-avatar';
import { PermissionEnum } from '@/enums/permission-enum';
import { useAppearance } from '@/hooks/use-appearance';
import { useCan } from '@/hooks/use-can';
import { useNavModules } from '@/hooks/use-nav-modules';
import { toggleAiAssistant } from '@/lib/ai-open';
import { toUrl } from '@/lib/utils';
import { search as searchRoute } from '@/routes';

/** Server result groups (`GlobalSearchService`) and how the palette shows them. */
const SEARCH_GROUP_ICONS: Record<string, LucideIcon> = {
    projects: FolderKanban,
    exceptions: Bug,
    projectGroups: FolderTree,
    users: User,
    groups: UsersRound,
};

type SearchAnswer = {
    groups: {
        type: string;
        items: {
            id: string;
            label: string;
            description: string | null;
            url: string;
            /** Project results (#287): the slug for the colour and the logo. */
            avatar: { slug: string; logoUrl: string | null } | null;
        }[];
    }[];
};

/**
 * Excubia's command palette (#274, app-ui optional pattern `command-palette`): the sidebar's
 * modules and a few actions, filtered locally, plus projects, project groups, users and
 * groups from the server search, which returns only what the user may open.
 */
export function AppCommandPalette({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const { can } = useCan();
    const { isAssistantEnabled } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const navModules = useNavModules();

    const groups = useMemo<CommandPaletteGroup[]>(() => {
        const isDark = resolvedAppearance === 'dark';

        return [
            {
                id: 'navigation',
                heading: t('palette.groups.navigation'),
                items: navModules.map((module) => ({
                    id: `nav:${module.id}`,
                    label: t(module.titleKey),
                    icon: module.icon,
                    href: toUrl(module.href),
                })),
            },
            {
                id: 'actions',
                heading: t('palette.groups.actions'),
                items: [
                    ...(isAssistantEnabled && can(PermissionEnum.CanUseAi)
                        ? [
                              {
                                  id: 'action:assistant-panel',
                                  label: t('palette.actions.assistantPanel'),
                                  icon: PanelRight,
                                  onSelect: toggleAiAssistant,
                              },
                          ]
                        : []),
                    {
                        id: 'action:appearance',
                        label: isDark
                            ? t('palette.actions.lightMode')
                            : t('palette.actions.darkMode'),
                        icon: isDark ? Sun : MoonStar,
                        onSelect: () =>
                            updateAppearance(isDark ? 'light' : 'dark'),
                    },
                ],
            },
        ];
    }, [
        t,
        can,
        navModules,
        isAssistantEnabled,
        resolvedAppearance,
        updateAppearance,
    ]);

    const search = useCallback(
        async (
            query: string,
            signal: AbortSignal,
        ): Promise<CommandPaletteGroup[]> => {
            const response = await fetch(
                searchRoute.url({ query: { query } }),
                {
                    signal,
                    headers: { Accept: 'application/json' },
                },
            );

            if (!response.ok) {
                throw new Error(`Search failed: ${response.status}`);
            }

            const answer = (await response.json()) as SearchAnswer;

            return answer.groups.map((group) => ({
                id: group.type,
                heading: t(`palette.groups.${group.type}`),
                items: group.items.map((item) => ({
                    id: item.id,
                    label: item.label,
                    description: item.description ?? undefined,
                    icon: item.avatar
                        ? projectAvatarIcon({
                              ...item.avatar,
                              name: item.label,
                          })
                        : SEARCH_GROUP_ICONS[group.type],
                    href: item.url,
                })),
            }));
        },
        [t],
    );

    return (
        <CommandPalette
            open={open}
            onOpenChange={onOpenChange}
            groups={groups}
            search={search}
            onNavigate={(href) => router.visit(href)}
            recentStorageKey="excubia.palette.recent"
        />
    );
}
