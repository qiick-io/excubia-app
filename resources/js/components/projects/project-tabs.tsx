import {
    Bell,
    Bug,
    CalendarClock,
    Gauge,
    History,
    LayoutGrid,
    ListChecks,
    ListOrdered,
    Package,
    Scale,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { GroupedViewTabs } from '@/components/grouped-view-tabs';
import type {
    ViewTabGroup,
    ViewTabSeverity,
} from '@/components/grouped-view-tabs';
import projectsRoutes from '@/routes/projects';

const VIEWS = [
    'overview',
    'findings',
    'licenses',
    'runtime',
    'ai',
    'exceptions',
    'schedule',
    'alerts',
    'plan',
    'history',
] as const;

/** A view (tab) of the project page, the last segment of its URL. */
export type ProjectView = (typeof VIEWS)[number];

const VIEW_ICONS = {
    overview: LayoutGrid,
    findings: ListChecks,
    licenses: Scale,
    runtime: Gauge,
    ai: Sparkles,
    exceptions: Bug,
    schedule: CalendarClock,
    alerts: Bell,
    plan: ListOrdered,
    history: History,
} as const;

export type ProjectViewCounts = {
    exceptions: { count: number; severity: ViewTabSeverity | null };
    findings: { count: number; severity: ViewTabSeverity | null };
};

/**
 * The project's ten views grouped by the question they answer (#275, app-ui optional pattern
 * `grouped-tabs`, variant `sub-menu`): one row under the header, each view still its own URL.
 * Exceptions and Findings carry their open count and severity, so the tabs say where to look.
 */
const VIEW_GROUPS: { id: string; views: readonly ProjectView[] }[] = [
    { id: 'overview', views: ['overview'] },
    { id: 'issues', views: ['exceptions', 'findings'] },
    { id: 'health', views: ['runtime', 'schedule', 'ai'] },
    { id: 'dependencies', views: ['licenses', 'plan'] },
    { id: 'alerts', views: ['alerts'] },
    { id: 'history', views: ['history'] },
];

const GROUP_ICONS: Record<string, LucideIcon> = {
    issues: Bug,
    health: Gauge,
    dependencies: Package,
};

/**
 * The grouped view tabs under the project header: each view links to its own URL, and the
 * Exceptions and Findings tabs carry their open count and severity.
 */
export function ProjectTabs({
    slug,
    view,
    counts,
}: {
    slug: string;
    view: ProjectView;
    counts?: ProjectViewCounts;
}) {
    const { t } = useTranslation();

    const groups: ViewTabGroup[] = VIEW_GROUPS.map((group) => ({
        id: group.id,
        label: t(`report.viewGroups.${group.id}`),
        icon: GROUP_ICONS[group.id],
        views: group.views.map((item) => {
            const viewCount =
                item === 'exceptions' || item === 'findings'
                    ? counts?.[item]
                    : undefined;

            return {
                id: item,
                label: t(`report.views.${item}`),
                icon: VIEW_ICONS[item],
                href:
                    item === 'overview'
                        ? projectsRoutes.show.url(slug)
                        : projectsRoutes.view.url({
                              project: slug,
                              view: item,
                          }),
                count: viewCount?.count,
                severity: viewCount?.severity ?? undefined,
            };
        }),
    }));

    return (
        <GroupedViewTabs
            groups={groups}
            activeViewId={view}
            ariaLabel={t('report.views.label')}
            variant="sub-menu"
        />
    );
}
