import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowUpRight,
    CalendarClock,
    CircleCheck,
    FolderKanban,
    Minus,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import type { FindingFix } from '@/components/reports/fix-actions';
import { FixActions } from '@/components/reports/fix-actions';
import {
    RuntimeSupportBadge,
    supportStatus,
} from '@/components/reports/runtime-support';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
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
import type { ProjectRow, RuntimeName, Severity } from '@/types';

const PERIODS = [7, 14, 30, 90] as const;
const DEFAULT_PERIOD = 14;
const ALL_PROJECTS = '__all__';

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
    scope,
    overview,
    trend,
}: {
    canViewProjects: boolean;
    projects: ProjectRow[];
    scope: { project: string | null; period: number };
    overview: Overview | null;
    trend: TrendPoint[];
}) {
    const { t } = useTranslation();
    const { can } = useCan();
    const selected = projects.find((project) => project.slug === scope.project);

    const visit = (next: {
        project?: string | null;
        period?: number;
    }): void => {
        const project =
            next.project !== undefined ? next.project : scope.project;
        const period = next.period ?? scope.period;

        router.get(
            dashboard.url({
                query: {
                    project: project ?? undefined,
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
                            can(PermissionEnum.ProjectsManage) ? (
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
                    <Select
                        value={scope.project ?? ALL_PROJECTS}
                        onValueChange={(value) =>
                            visit({
                                project: value === ALL_PROJECTS ? null : value,
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
                        <KpiCard
                            title={t('dashboard.kpi.findings')}
                            value={kpis.findings.total}
                            previous={kpis.findings.previous}
                            periodLabel={periodLabel}
                        >
                            <SeverityCounts counts={kpis.findings.counts} />
                        </KpiCard>
                        <KpiCard
                            title={t('dashboard.kpi.atRisk')}
                            value={kpis.atRisk.value}
                            previous={kpis.atRisk.previous}
                            periodLabel={periodLabel}
                            description={t('dashboard.kpi.atRiskDescription')}
                        />
                        <KpiCard
                            title={t('dashboard.kpi.endOfSupport')}
                            value={kpis.endOfSupport.value}
                            previous={kpis.endOfSupport.previous}
                            periodLabel={periodLabel}
                            description={t(
                                'dashboard.kpi.endOfSupportDescription',
                            )}
                        />
                        <KpiCard
                            title={t('dashboard.kpi.stale')}
                            value={kpis.stale.value}
                            previous={null}
                            periodLabel={periodLabel}
                            description={t('dashboard.kpi.staleDescription', {
                                total: kpis.stale.total,
                            })}
                        />
                    </section>

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
                        {selected ? null : <ProjectsCard projects={projects} />}
                    </section>
                </div>
            </PageLayout>
        </>
    );
}

/**
 * A key number with its change against the previous period (fewer is better for all of them),
 * as the externa-core stat tiles. The change names its direction in text, not colour only.
 */
function KpiCard({
    title,
    value,
    previous,
    periodLabel,
    description,
    children,
}: {
    title: string;
    value: number;
    previous: number | null;
    periodLabel: string;
    description?: string;
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    const delta = previous === null ? null : value - previous;

    return (
        <Card className="xl:col-span-3">
            <CardHeader className="pb-3">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                <div className="flex items-baseline gap-2">
                    <span className="text-2xl font-semibold tabular-nums">
                        {value.toLocaleString()}
                    </span>
                    {delta === null ? null : (
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 text-xs font-medium',
                                delta > 0 && 'text-destructive',
                                delta < 0 && 'text-success',
                                delta === 0 && 'text-muted-foreground',
                            )}
                        >
                            {delta > 0 ? (
                                <ArrowUpRight
                                    className="size-3.5"
                                    aria-hidden
                                />
                            ) : delta < 0 ? (
                                <ArrowDownRight
                                    className="size-3.5"
                                    aria-hidden
                                />
                            ) : (
                                <Minus className="size-3.5" aria-hidden />
                            )}
                            {delta === 0
                                ? t('dashboard.kpi.unchanged')
                                : t(
                                      delta > 0
                                          ? 'dashboard.kpi.up'
                                          : 'dashboard.kpi.down',
                                      { count: Math.abs(delta) },
                                  )}
                        </span>
                    )}
                </div>
                {children}
                {description || delta !== null ? (
                    <p className="text-xs text-muted-foreground">
                        {[description, delta !== null ? periodLabel : null]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}

/**
 * The grouped findings across projects, worst first: the Sentry "issues" of Excubia.
 */
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
                                                                href={`${projectsRoutes.show.url(project.slug)}?view=findings`}
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
                                                href={`${projectsRoutes.show.url(row.project.slug)}?view=findings`}
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
 * Every project in one compact table, worst first, each linking to its page.
 */
function ProjectsCard({ projects }: { projects: ProjectRow[] }) {
    const { t } = useTranslation();
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
