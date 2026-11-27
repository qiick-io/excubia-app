import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, FolderKanban, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNumberFormat } from '@/hooks/use-number-format';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { KpiTile, kpiDeltaOf } from '@/components/charts/kpi-tile';
import { historySparkline, KpiRatio } from '@/components/dashboard/kpi-history';
import { FleetFindings } from '@/components/dashboard/fleet-findings';
import { ReportCompositions } from '@/components/dashboard/report-compositions';
import { Button } from '@/components/ui/button';
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
import type {
    TrendPoint,
    Overview,
    AssignedRow,
    DashboardHistory,
} from '@/types/dashboard';
import {
    RequestsKpi,
    TelemetryRow,
} from '@/components/dashboard/telemetry-row';
import { AssignedToYouCard } from '@/components/dashboard/assigned-to-you-card';
import { TopIssuesCard } from '@/components/dashboard/top-issues-card';
import { EndOfSupportCard } from '@/components/dashboard/end-of-support-card';
import { ConfigurationCard } from '@/components/dashboard/configuration-card';
import { LicensesCard } from '@/components/dashboard/licenses-card';
import { ProjectsCard } from '@/components/dashboard/projects-card';
import { cn } from '@/lib/utils';

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
    kpiHistory = {},
    liveHealth,
    assignedToMe = [],
}: {
    /** Open exception groups and findings assigned to the user, per project (#254). */
    assignedToMe?: AssignedRow[];
    canViewProjects: boolean;
    projects: ProjectRow[];
    /** Project groups holding a project the user can see (#252). */
    projectGroups: { id: number; name: string }[];
    scope: {
        project: string | null;
        group: number | null;
        period: number;
        /** `?assigned=me`: the top issues are those assigned to the user (#332). */
        isAssignedToMe: boolean;
    };
    overview: Overview | null;
    trend: TrendPoint[];
    kpiHistory?: DashboardHistory;
    /** Project slug → live health, for the projects that send health samples (#143). */
    liveHealth: Record<string, LiveHealthSummary>;
}) {
    const { t } = useTranslation();
    const formatNumber = useNumberFormat();
    const { can } = useCan();
    const selected = projects.find((project) => project.slug === scope.project);

    const visit = (next: {
        project?: string | null;
        group?: number | null;
        period?: number;
        isAssignedToMe?: boolean;
    }): void => {
        const project =
            next.project !== undefined ? next.project : scope.project;
        const group = next.group !== undefined ? next.group : scope.group;
        const period = next.period ?? scope.period;
        const isAssignedToMe = next.isAssignedToMe ?? scope.isAssignedToMe;

        router.get(
            dashboard.url({
                query: {
                    project: project ?? undefined,
                    group: group ?? undefined,
                    period: period === DEFAULT_PERIOD ? undefined : period,
                    assigned: isAssignedToMe ? 'me' : undefined,
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
    const keyRowRequests = overview.exceptions ? null : overview.runtime;
    const periodLabel = t('dashboard.period.previous', { count: scope.period });
    const scopedProjects = selected ? [selected] : projects;
    const reports = scopedProjects.flatMap(
        (project) => project.latest_report ?? [],
    );
    const checks = reports.reduce(
        (totals, report) => ({
            fail: totals.fail + report.checks.fail,
            warn: totals.warn + report.checks.warn,
            pass: totals.pass + report.checks.pass,
            skipped: totals.skipped + report.checks.skipped,
        }),
        { fail: 0, warn: 0, pass: 0, skipped: 0 },
    );

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
                    <section
                        className={cn(
                            // An odd last tile spans the two-column row (app-ui docs/charts.md).
                            'grid grid-cols-1 gap-4 sm:grid-cols-2 sm:*:last:odd:col-span-2 xl:*:last:odd:col-span-1',
                            keyRowRequests
                                ? 'xl:grid-cols-5'
                                : 'xl:grid-cols-4',
                        )}
                    >
                        <KpiTile
                            label={t('dashboard.kpi.findings')}
                            value={formatNumber(kpis.findings.total)}
                            delta={kpiDeltaOf(
                                kpis.findings.total,
                                kpis.findings.previous,
                                false,
                                formatNumber,
                            )}
                            secondary={
                                kpis.findings.previous === null
                                    ? t('dashboard.kpi.findingsContext')
                                    : periodLabel
                            }
                            chart={historySparkline(kpiHistory.findings)}
                        />
                        <KpiTile
                            label={t('dashboard.kpi.atRisk')}
                            value={formatNumber(kpis.atRisk.value)}
                            delta={kpiDeltaOf(
                                kpis.atRisk.value,
                                kpis.atRisk.previous,
                                false,
                                formatNumber,
                            )}
                            secondary={
                                kpis.atRisk.previous === null
                                    ? t('dashboard.kpi.atRiskDescription')
                                    : periodLabel
                            }
                        />
                        <KpiTile
                            label={t('dashboard.kpi.endOfSupport')}
                            value={formatNumber(kpis.endOfSupport.value)}
                            delta={kpiDeltaOf(
                                kpis.endOfSupport.value,
                                kpis.endOfSupport.previous,
                                false,
                                formatNumber,
                            )}
                            secondary={
                                kpis.endOfSupport.previous === null
                                    ? t('dashboard.kpi.endOfSupportDescription')
                                    : periodLabel
                            }
                        />
                        <KpiTile
                            label={t('dashboard.kpi.stale')}
                            value={
                                <KpiRatio
                                    value={kpis.stale.value}
                                    total={kpis.stale.total}
                                />
                            }
                            secondary={t('dashboard.kpi.staleContext')}
                        />
                        {keyRowRequests ? (
                            <RequestsKpi
                                runtime={keyRowRequests}
                                history={kpiHistory.requests}
                                periodLabel={periodLabel}
                            />
                        ) : null}
                    </section>

                    {reports.length > 0 && (
                        <ReportCompositions
                            findings={kpis.findings.counts}
                            checks={checks}
                        />
                    )}

                    {assignedToMe.length > 0 ? (
                        <AssignedToYouCard rows={assignedToMe} />
                    ) : null}

                    {overview.exceptions ? (
                        <TelemetryRow
                            periodDays={scope.period}
                            runtime={overview.runtime}
                            exceptions={overview.exceptions}
                            history={kpiHistory}
                            periodLabel={periodLabel}
                        />
                    ) : null}

                    <FleetFindings projects={scopedProjects} trend={trend} />

                    <TopIssuesCard
                        issues={overview.topIssues}
                        total={overview.topIssuesTotal}
                        isAssignedToMe={scope.isAssignedToMe}
                        onAssignedToMeChange={(isPressed) =>
                            visit({ isAssignedToMe: isPressed })
                        }
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

                    {/* Two short cards share a row instead of each taking a whole one. */}
                    <section className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <EndOfSupportCard rows={overview.endOfSupport} />
                        <LicensesCard rows={overview.licenses} />
                    </section>
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
