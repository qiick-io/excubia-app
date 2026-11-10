import { Head, Link, router, usePoll } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarClock,
    CircleCheck,
    FolderKanban,
    Plus,
    Scale,
    ShieldCheck,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { CHECK_STATUS_VARIANTS } from '@/components/reports/check-details';
import type { FindingFix } from '@/components/reports/fix-actions';
import { StatCard, trendOf } from '@/components/stat-card';
import { FixActions } from '@/components/reports/fix-actions';
import { LICENSE_KIND_VARIANTS } from '@/components/reports/licenses-view';
import {
    RuntimeSupportBadge,
    supportStatus,
} from '@/components/reports/runtime-support';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
    severitySummary,
} from '@/components/reports/severity-badge';
import type { SeverityChartPoint } from '@/components/reports/severity-chart';
import { SeverityStackedBarChart } from '@/components/reports/severity-chart';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import projectsRoutes from '@/routes/projects';
import type {
    FlaggedLicense,
    LiveHealthSummary,
    ProjectRow,
    RuntimeName,
    Severity,
} from '@/types';

const PERIODS = [7, 14, 30, 90] as const;
const DEFAULT_PERIOD = 14;
const ALL_PROJECTS = '__all__';
const LIVE_HEALTH_POLL_MS = 60_000;

type TrendPoint = { date: string } & Record<Severity, number>;
type ProjectRef = { slug: string; name: string };

type TopIssue = {
    key: string;
    severity: Severity;
    checkId: string;
    checkName: string | null;
    package: string;
    message: string;
    fixHint: string;
    advisoryId: string | null;
    evidenceUrl: string | null;
    installed: string[];
    fixedVersions: string[];
    projects: (ProjectRef & { installed: string | null })[];
    fix: FindingFix;
};

type EndOfSupportRow = {
    project: ProjectRef;
    runtime: RuntimeName;
    version: string | null;
    cycle: string | null;
    securitySupportEndsOn: string | null;
    activeSupportEndsOn: string | null;
};

type ConfigurationRow = {
    project: ProjectRef;
    checkId: string;
    checkName: string | null;
    severity: Severity;
    subject: string;
    message: string;
    fixHint: string;
    fix: FindingFix;
};

type LicenseRow = {
    license: string;
    kind: FlaggedLicense['kind'];
    packages: string[];
    projects: ProjectRef[];
};

type Overview = {
    kpis: {
        findings: {
            counts: Record<Severity, number>;
            total: number;
            previous: number | null;
        };
        atRisk: { value: number; previous: number | null };
        endOfSupport: { value: number; previous: number | null };
        stale: { value: number; total: number };
    };
    topIssues: TopIssue[];
    topIssuesTotal: number;
    endOfSupport: EndOfSupportRow[];
    configuration: ConfigurationRow[];
    /** Denied, copyleft and unknown licenses of the latest reports (#108). */
    licenses: LicenseRow[];
    runtime: {
        requests: number;
        errors: number;
        previousRequests: number;
    } | null;
    exceptions: {
        open: number;
        new: number;
        top: {
            id: number;
            class: string;
            message: string;
            count: number;
            lastSeen: string;
            context: string;
            project: ProjectRef;
        }[];
    } | null;
};

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

/**
 * Runtime and exceptions across the scoped projects (#24, #26): requests and 5xx for the
 * period, open exception groups, and the open groups seen most recently.
 */
function TelemetryRow({
    runtime,
    exceptions,
    periodLabel,
}: {
    runtime: Overview['runtime'];
    exceptions: Overview['exceptions'];
    periodLabel: string;
}) {
    const { t } = useTranslation();
    const errorRate =
        runtime && runtime.requests > 0
            ? (runtime.errors / runtime.requests) * 100
            : 0;

    return (
        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-12">
            {runtime ? (
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.telemetry.requests')}
                    value={runtime.requests.toLocaleString()}
                    trend={trendOf(runtime.requests, runtime.previousRequests)}
                    headline={t('dashboard.telemetry.errors', {
                        count: runtime.errors,
                        rate: errorRate.toFixed(errorRate < 1 ? 2 : 1),
                    })}
                    context={periodLabel}
                />
            ) : null}
            {exceptions ? (
                <StatCard
                    className="xl:col-span-3"
                    label={t('dashboard.telemetry.openExceptions')}
                    value={exceptions.open.toLocaleString()}
                    headline={t('dashboard.telemetry.newExceptions', {
                        count: exceptions.new,
                    })}
                    context={t('dashboard.telemetry.openExceptionsContext')}
                />
            ) : null}
            {exceptions ? (
                <Card className="sm:col-span-2 xl:col-span-6">
                    <CardHeader className="pb-3">
                        <CardTitle>
                            {t('dashboard.telemetry.topExceptions')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {exceptions.top.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('dashboard.telemetry.noOpenExceptions')}
                            </p>
                        ) : (
                            <div className="max-h-48 overflow-auto">
                                <Table>
                                    <TableBody>
                                        {exceptions.top.map((group) => (
                                            <TableRow key={group.id}>
                                                <TableCell className="max-w-[24rem] min-w-0">
                                                    <Link
                                                        href={projectsRoutes.view.url(
                                                            {
                                                                project:
                                                                    group
                                                                        .project
                                                                        .slug,
                                                                view: 'exceptions',
                                                            },
                                                        )}
                                                        className="block truncate font-mono text-xs font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {group.class}
                                                    </Link>
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        {group.project.name} ·{' '}
                                                        {group.context}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {group.count.toLocaleString()}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            ) : null}
        </section>
    );
}

/**
 * The grouped findings across projects, worst first: the Sentry "issues" of Excubia.
 */
/** A project's open items assigned to the signed-in user (#254). */
type AssignedRow = {
    slug: string;
    name: string;
    exceptions: number;
    findings: number;
};

/**
 * "Assigned to you" (#254): per project, the open exception groups and findings the user is on,
 * each count linking to its tab with the "Assigned to me" filter on (`?assigned=me`). Shown
 * only when something is assigned.
 */
function AssignedToYouCard({ rows }: { rows: AssignedRow[] }) {
    const { t } = useTranslation();
    const mine = { query: { assigned: 'me' } };

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.assigned.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.assigned.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="flex flex-col divide-y divide-border text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.slug}
                            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2"
                        >
                            <span className="min-w-0 truncate font-medium">
                                {row.name}
                            </span>
                            <span className="flex gap-4">
                                {row.exceptions > 0 ? (
                                    <Link
                                        className="text-primary underline-offset-4 hover:underline"
                                        href={projectsRoutes.view.url(
                                            {
                                                project: row.slug,
                                                view: 'exceptions',
                                            },
                                            mine,
                                        )}
                                    >
                                        {t('dashboard.assigned.exceptions', {
                                            count: row.exceptions,
                                        })}
                                    </Link>
                                ) : null}
                                {row.findings > 0 ? (
                                    <Link
                                        className="text-primary underline-offset-4 hover:underline"
                                        href={projectsRoutes.view.url(
                                            {
                                                project: row.slug,
                                                view: 'findings',
                                            },
                                            mine,
                                        )}
                                    >
                                        {t('dashboard.assigned.findings', {
                                            count: row.findings,
                                        })}
                                    </Link>
                                ) : null}
                            </span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function TopIssuesCard({
    issues,
    total,
}: {
    issues: TopIssue[];
    total: number;
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.topIssues.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.topIssues.description', {
                        shown: issues.length,
                        total,
                    })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {issues.length === 0 ? (
                    <PageEmptyState
                        icon={CircleCheck}
                        title={t('dashboard.topIssues.emptyTitle')}
                        description={t('dashboard.topIssues.emptyDescription')}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-96 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('report.severityLabel')}
                                    </TableHead>
                                    <TableHead>
                                        {t('dashboard.topIssues.issue')}
                                    </TableHead>
                                    <TableHead>
                                        {t('report.installed')}
                                    </TableHead>
                                    <TableHead>{t('report.fixedIn')}</TableHead>
                                    <TableHead>
                                        {t('dashboard.topIssues.projects')}
                                    </TableHead>
                                    <TableHead className="w-[1%] text-right">
                                        {t('fix.actions')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {issues.map((issue) => (
                                    <TableRow key={issue.key}>
                                        <TableCell>
                                            <SeverityBadge
                                                severity={issue.severity}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-[28rem] whitespace-normal">
                                            <div className="font-medium">
                                                {issue.package}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {issue.checkName ??
                                                    issue.checkId}
                                                {issue.advisoryId ? (
                                                    <span className="font-mono">
                                                        {' · '}
                                                        {issue.advisoryId}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {issue.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {issue.installed.join(', ') || '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {issue.fixedVersions.join(', ') ||
                                                '—'}
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <div className="flex flex-wrap gap-1">
                                                {issue.projects.map(
                                                    (project) => (
                                                        <Badge
                                                            key={project.slug}
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={projectsRoutes.view.url(
                                                                    {
                                                                        project:
                                                                            project.slug,
                                                                        view: 'findings',
                                                                    },
                                                                )}
                                                            >
                                                                {project.name}
                                                            </Link>
                                                        </Badge>
                                                    ),
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <FixActions fix={issue.fix} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function EndOfSupportCard({ rows }: { rows: EndOfSupportRow[] }) {
    const { t } = useTranslation();

    return (
        <Card className="xl:col-span-4">
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.endOfSupport.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.endOfSupport.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <PageEmptyState
                        icon={CalendarClock}
                        title={t('dashboard.endOfSupport.emptyTitle')}
                        description={t(
                            'dashboard.endOfSupport.emptyDescription',
                        )}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-64 overflow-auto">
                        <Table>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow
                                        key={`${row.project.slug}-${row.runtime}`}
                                    >
                                        <TableCell className="min-w-0">
                                            <div className="truncate font-medium">
                                                {t(
                                                    `report.runtime.${row.runtime}`,
                                                )}{' '}
                                                <span className="font-mono text-xs">
                                                    {row.version ?? row.cycle}
                                                </span>
                                            </div>
                                            <Link
                                                href={projectsRoutes.show.url(
                                                    row.project.slug,
                                                )}
                                                className="truncate text-xs text-muted-foreground underline-offset-4 hover:underline"
                                            >
                                                {row.project.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="text-xs tabular-nums">
                                                {row.securitySupportEndsOn}
                                            </div>
                                            <RuntimeSupportBadge
                                                runtime={{
                                                    version: row.version,
                                                    cycle: row.cycle,
                                                    security_support_ends_on:
                                                        row.securitySupportEndsOn,
                                                    active_support_ends_on:
                                                        row.activeSupportEndsOn,
                                                }}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ConfigurationCard({
    rows,
    className,
}: {
    rows: ConfigurationRow[];
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={cn('xl:col-span-5', className)}>
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.configuration.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.configuration.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <PageEmptyState
                        icon={ShieldCheck}
                        title={t('dashboard.configuration.emptyTitle')}
                        description={t(
                            'dashboard.configuration.emptyDescription',
                        )}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <Table>
                            <TableBody>
                                {rows.map((row, index) => (
                                    <TableRow
                                        key={`${row.project.slug}-${row.checkId}-${index}`}
                                    >
                                        <TableCell>
                                            <SeverityBadge
                                                severity={row.severity}
                                            />
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <div className="font-medium">
                                                {row.checkName ?? row.checkId}
                                            </div>
                                            <div className="text-sm">
                                                {row.message}
                                            </div>
                                            <Link
                                                href={projectsRoutes.view.url({
                                                    project: row.project.slug,
                                                    view: 'findings',
                                                })}
                                                className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                                            >
                                                {row.project.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <FixActions fix={row.fix} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Fleet licenses (#108): which projects use a denied, strong copyleft or unknown license, and
 * through which packages. Empty when no latest report flags one.
 */
function LicensesCard({ rows }: { rows: LicenseRow[] }) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('licenses.fleetTitle')}</CardTitle>
                <CardDescription>
                    {t('licenses.fleetDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <PageEmptyState
                        icon={Scale}
                        title={t('licenses.noneFlaggedTitle')}
                        description={t('licenses.noneFlagged')}
                        compact
                        className="border-0"
                    />
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('licenses.license')}
                                    </TableHead>
                                    <TableHead>{t('licenses.kind')}</TableHead>
                                    <TableHead>
                                        {t('dashboard.projects.title')}
                                    </TableHead>
                                    <TableHead>
                                        {t('details.package')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow
                                        key={`${row.kind}-${row.license}`}
                                    >
                                        <TableCell className="font-mono text-xs">
                                            {row.license}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    LICENSE_KIND_VARIANTS[
                                                        row.kind
                                                    ]
                                                }
                                            >
                                                {t(
                                                    `licenses.kinds.${row.kind}`,
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <div className="flex flex-wrap gap-x-2">
                                                {row.projects.map((project) => (
                                                    <Link
                                                        key={project.slug}
                                                        href={projectsRoutes.view.url(
                                                            {
                                                                project:
                                                                    project.slug,
                                                                view: 'licenses',
                                                            },
                                                        )}
                                                        className="text-sm underline-offset-4 hover:underline"
                                                    >
                                                        {project.name}
                                                    </Link>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs whitespace-normal">
                                            {row.packages.join(', ')}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Every project in one compact table, worst first, each linking to its page, with its live
 * health (#143) when it sends health samples. Reloads only `liveHealth` every minute.
 */
function ProjectsCard({
    projects,
    liveHealth,
}: {
    projects: ProjectRow[];
    liveHealth: Record<string, LiveHealthSummary>;
}) {
    const { t } = useTranslation();

    usePoll(LIVE_HEALTH_POLL_MS, { only: ['liveHealth'] });

    const rows = [...projects].sort(
        (first, second) => severityRank(first) - severityRank(second),
    );

    return (
        <Card className="xl:col-span-7">
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.projects.title')}</CardTitle>
                <CardDescription>
                    {t('dashboard.projects.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="max-h-80 overflow-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('common.name')}</TableHead>
                                <TableHead>{t('report.findings')}</TableHead>
                                <TableHead>
                                    {t('checks.category.infrastructure')}
                                </TableHead>
                                <TableHead>
                                    {t('report.liveHealth.title')}
                                </TableHead>
                                <TableHead>{t('report.runtime.php')}</TableHead>
                                <TableHead>
                                    {t('report.runtime.laravel')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('projects.lastReport')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((project) => {
                                const report = project.latest_report ?? null;
                                const health = liveHealth[project.slug] ?? null;

                                return (
                                    <TableRow key={project.id}>
                                        <TableCell className="max-w-[14rem]">
                                            <Link
                                                href={projectsRoutes.show.url(
                                                    project.slug,
                                                )}
                                                className="block truncate font-medium underline-offset-4 hover:underline"
                                            >
                                                {project.name}
                                            </Link>
                                            <div className="flex items-center gap-1">
                                                {report ? (
                                                    <SeverityBadge
                                                        severity={
                                                            report.worst_severity
                                                        }
                                                    />
                                                ) : null}
                                                {project.is_stale ? (
                                                    <Badge variant="muted">
                                                        {t('dashboard.stale')}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {report ? (
                                                <SeverityCounts
                                                    counts={report.counts}
                                                />
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    {t('dashboard.noReports')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {report?.infrastructure ? (
                                                <Badge
                                                    variant={
                                                        CHECK_STATUS_VARIANTS[
                                                            report
                                                                .infrastructure
                                                        ]
                                                    }
                                                >
                                                    {t(
                                                        `report.status.${report.infrastructure}`,
                                                    )}
                                                </Badge>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell data-test="live-health-summary">
                                            {health ? (
                                                <div className="flex items-center gap-1">
                                                    <Badge
                                                        variant={
                                                            CHECK_STATUS_VARIANTS[
                                                                health.status
                                                            ]
                                                        }
                                                    >
                                                        {health.failingChecks >
                                                        0
                                                            ? t(
                                                                  'dashboard.liveHealthFailing',
                                                                  {
                                                                      count: health.failingChecks,
                                                                  },
                                                              )
                                                            : t(
                                                                  `report.status.${health.status}`,
                                                              )}
                                                    </Badge>
                                                    {health.hasStaleServers ? (
                                                        <Badge variant="muted">
                                                            {t(
                                                                'dashboard.liveHealthStale',
                                                            )}
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        {(['php', 'laravel'] as const).map(
                                            (name) => {
                                                const runtime =
                                                    report?.runtime?.[name] ??
                                                    null;
                                                const status = supportStatus(
                                                    runtime?.security_support_ends_on,
                                                );

                                                return (
                                                    <TableCell
                                                        key={name}
                                                        className={cn(
                                                            'font-mono text-xs',
                                                            status ===
                                                                'ended' &&
                                                                'text-destructive',
                                                        )}
                                                    >
                                                        {runtime?.version ??
                                                            runtime?.cycle ??
                                                            '—'}
                                                    </TableCell>
                                                );
                                            },
                                        )}
                                        <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                                            {project.last_report_at
                                                ? new Date(
                                                      project.last_report_at,
                                                  ).toLocaleString()
                                                : t('projects.never')}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}

function severityRank(project: ProjectRow): number {
    const report = project.latest_report ?? null;

    if (report === null) {
        return SEVERITIES.length + 1;
    }

    return report.worst_severity === null
        ? SEVERITIES.length
        : SEVERITIES.indexOf(report.worst_severity);
}

Dashboard.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Dashboard', href: dashboard() }]}>
        {page}
    </AppLayout>
);
