import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, FolderKanban, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { StatCard, trendOf } from '@/components/stat-card';
import { severitySummary } from '@/components/reports/severity-badge';
import type { SeverityChartPoint } from '@/components/reports/severity-chart';
import { SeverityStackedBarChart } from '@/components/reports/severity-chart';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import projectsRoutes from '@/routes/projects';
import type { LiveHealthSummary, ProjectRow } from '@/types';
import type { TrendPoint, Overview, AssignedRow } from '@/types/dashboard';
import { TelemetryRow } from '@/components/dashboard/telemetry-row';
import { AssignedToYouCard } from '@/components/dashboard/assigned-to-you-card';
import { TopIssuesCard } from '@/components/dashboard/top-issues-card';
import { EndOfSupportCard } from '@/components/dashboard/end-of-support-card';
import { ConfigurationCard } from '@/components/dashboard/configuration-card';
import { LicensesCard } from '@/components/dashboard/licenses-card';
import { ProjectsCard } from '@/components/dashboard/projects-card';

const PERIODS = [7, 14, 30, 90] as const;
const DEFAULT_PERIOD = 14;
const ALL_PROJECTS = '__all__';

/**
 * Issue-first overview (#77), following the overview → filter → details convention: a context
 * bar (project, period) in the filter row, key numbers compared with the previous period, the
 * findings trend, then actionable lists (top issues, end of support, configuration) and the
 * projects. Every row links to the detail. Layout from the externa-core dashboard: flat cards
 * in the 12-column grid, stat tiles, tables in cards.
 */
export default function Dashboard({
    canViewProjects,
    projects,
    projectGroups,
    scope,
    overview,
    trend,
    liveHealth,
    assignedToMe = [],
}: {
    /** Open exception groups and findings assigned to the user, per project (#254). */
    assignedToMe?: AssignedRow[];
    canViewProjects: boolean;
    projects: ProjectRow[];
    /** Project groups holding a project the user can see (#252). */
    projectGroups: { id: number; name: string }[];
    scope: { project: string | null; group: number | null; period: number };
    overview: Overview | null;
    trend: TrendPoint[];
    /** Project slug → live health, for the projects that send health samples (#143). */
    liveHealth: Record<string, LiveHealthSummary>;
}) {
    const { t } = useTranslation();
    const { can } = useCan();
    const selected = projects.find((project) => project.slug === scope.project);

    const visit = (next: {
        project?: string | null;
        group?: number | null;
        period?: number;
    }): void => {
        const project =
            next.project !== undefined ? next.project : scope.project;
        const group = next.group !== undefined ? next.group : scope.group;
        const period = next.period ?? scope.period;

        router.get(
            dashboard.url({
                query: {
                    project: project ?? undefined,
                    group: group ?? undefined,
                    period: period === DEFAULT_PERIOD ? undefined : period,
                },
            }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    if (!canViewProjects || overview === null) {
        return (
            <>
                <Head title={t('nav.dashboard')} />
                <PageLayout>
                    <p className="text-sm text-muted-foreground">
                        {t('dashboard.noAccess')}
                    </p>
                </PageLayout>
            </>
        );
    }

    if (projects.length === 0) {
        return (
            <>
                <Head title={t('nav.dashboard')} />
                <PageLayout>
                    <PageEmptyState
                        icon={FolderKanban}
                        title={t('dashboard.emptyTitle')}
                        description={t('dashboard.emptyDescription')}
                        action={
                            can(PermissionEnum.CanCreateProjects) ? (
                                <Button asChild>
                                    <Link href={projectsRoutes.index.url()}>
                                        <Plus className="size-4" />
                                        {t('projects.newProject')}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                </PageLayout>
            </>
        );
    }

    const { kpis } = overview;
    const periodLabel = t('dashboard.period.previous', {
        count: scope.period,
    });
    const chartData: SeverityChartPoint[] = trend.map((point) => ({
        ...point,
        label: new Date(`${point.date}T00:00:00`).toLocaleDateString(
            undefined,
            { day: 'numeric', month: 'short' },
        ),
    }));

    return (
        <>
            <Head title={t('nav.dashboard')} />
            <PageLayout
                scrollContent
                filters={
                    <div className="flex items-center gap-1.5">
                        {projectGroups.length > 0 ? (
                            <Select
                                value={
                                    scope.group === null
                                        ? ALL_PROJECTS
                                        : String(scope.group)
                                }
                                onValueChange={(value) =>
                                    visit({
                                        group:
                                            value === ALL_PROJECTS
                                                ? null
                                                : Number(value),
                                        project: null,
                                    })
                                }
                            >
                                <SelectTrigger
                                    aria-label={t('dashboard.scope.group')}
                                    className="w-48"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_PROJECTS}>
                                        {t('dashboard.scope.allGroups')}
                                    </SelectItem>
                                    {projectGroups.map((projectGroup) => (
                                        <SelectItem
                                            key={projectGroup.id}
                                            value={String(projectGroup.id)}
                                        >
                                            {projectGroup.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : null}
                        <Select
                            value={scope.project ?? ALL_PROJECTS}
                            onValueChange={(value) =>
                                visit({
                                    project:
                                        value === ALL_PROJECTS ? null : value,
                                })
                            }
                        >
                            <SelectTrigger
                                aria-label={t('dashboard.scope.project')}
                                className="w-64"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_PROJECTS}>
                                    {t('dashboard.scope.allProjects', {
                                        count: projects.length,
                                    })}
                                </SelectItem>
                                {projects.map((project) => (
                                    <SelectItem
                                        key={project.slug}
                                        value={project.slug}
                                    >
                                        {project.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
                filtersRight={
                    <div className="flex items-center gap-1.5">
                        {selected ? (
                            <Button variant="outline" asChild>
                                <Link
                                    href={projectsRoutes.show.url(
                                        selected.slug,
                                    )}
                                >
                                    {t('dashboard.scope.openProject')}
                                    <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        ) : null}
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={String(scope.period)}
                            onValueChange={(value) => {
                                if (value) {
                                    visit({ period: Number(value) });
                                }
                            }}
                            aria-label={t('dashboard.period.label')}
                        >
                            {PERIODS.map((period) => (
                                <ToggleGroupItem
                                    key={period}
                                    value={String(period)}
                                    className="px-2.5"
                                >
                                    {t('dashboard.period.days', {
                                        count: period,
                                    })}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>
                }
            >
                <div className="flex flex-col gap-4">
                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
                        <StatCard
                            className="xl:col-span-3"
                            label={t('dashboard.kpi.findings')}
                            value={kpis.findings.total.toLocaleString()}
                            trend={trendOf(
                                kpis.findings.total,
                                kpis.findings.previous,
                            )}
                            headline={severitySummary(kpis.findings.counts, t)}
                            context={
                                kpis.findings.previous === null
                                    ? t('dashboard.kpi.findingsContext')
                                    : periodLabel
                            }
                        />
                        <StatCard
                            className="xl:col-span-3"
                            label={t('dashboard.kpi.atRisk')}
                            value={kpis.atRisk.value.toLocaleString()}
                            trend={trendOf(
                                kpis.atRisk.value,
                                kpis.atRisk.previous,
                            )}
                            headline={t('dashboard.kpi.atRiskDescription')}
                            context={
                                kpis.atRisk.previous === null
                                    ? undefined
                                    : periodLabel
                            }
                        />
                        <StatCard
                            className="xl:col-span-3"
                            label={t('dashboard.kpi.endOfSupport')}
                            value={kpis.endOfSupport.value.toLocaleString()}
                            trend={trendOf(
                                kpis.endOfSupport.value,
                                kpis.endOfSupport.previous,
                            )}
                            headline={t(
                                'dashboard.kpi.endOfSupportDescription',
                            )}
                            context={
                                kpis.endOfSupport.previous === null
                                    ? undefined
                                    : periodLabel
                            }
                        />
                        <StatCard
                            className="xl:col-span-3"
                            label={t('dashboard.kpi.stale')}
                            value={kpis.stale.value.toLocaleString()}
                            headline={t('dashboard.kpi.staleHeadline', {
                                total: kpis.stale.total,
                            })}
                            context={t('dashboard.kpi.staleContext')}
                        />
                    </section>

                    {assignedToMe.length > 0 ? (
                        <AssignedToYouCard rows={assignedToMe} />
                    ) : null}

                    {overview.runtime || overview.exceptions ? (
                        <TelemetryRow
                            runtime={overview.runtime}
                            exceptions={overview.exceptions}
                            periodLabel={periodLabel}
                        />
                    ) : null}

                    <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                        <Card className="xl:col-span-8">
                            <CardHeader className="pb-3">
                                <CardTitle>
                                    {t('dashboard.trend.title')}
                                </CardTitle>
                                <CardDescription>
                                    {t('dashboard.trend.description', {
                                        count: scope.period,
                                    })}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="h-64">
                                <SeverityStackedBarChart
                                    data={chartData}
                                    label={t('dashboard.trend.label', {
                                        count: scope.period,
                                        total: kpis.findings.total,
                                    })}
                                />
                            </CardContent>
                        </Card>
                        <EndOfSupportCard rows={overview.endOfSupport} />
                    </section>

                    <TopIssuesCard
                        issues={overview.topIssues}
                        total={overview.topIssuesTotal}
                    />

                    <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                        <ConfigurationCard
                            rows={overview.configuration}
                            className={selected ? 'xl:col-span-12' : undefined}
                        />
                        {selected ? null : (
                            <ProjectsCard
                                projects={projects}
                                liveHealth={liveHealth}
                            />
                        )}
                    </section>

                    <LicensesCard rows={overview.licenses} />
                </div>
            </PageLayout>
        </>
    );
}

Dashboard.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Dashboard', href: dashboard() }]}>
        {page}
    </AppLayout>
);
