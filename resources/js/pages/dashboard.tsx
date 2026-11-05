import { Head, Link } from '@inertiajs/react';
import {
    ArrowDownAZ,
    ArrowUpAZ,
    FolderKanban,
    LayoutGrid,
    List,
    Plus,
    SearchX,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { FilterSearch } from '@/components/layout/page-header';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import {
    RuntimeSupportBadge,
    runtimeLabel,
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

type TrendPoint = { date: string } & Record<Severity, number>;

type SortField = 'severity' | 'name' | 'last_report';
type SortDirection = 'asc' | 'desc';
type DashboardView = 'cards' | 'table';

/** Runtimes shown on the overview; Node stays on the project page. */
const OVERVIEW_RUNTIMES: RuntimeName[] = ['php', 'laravel'];

/**
 * Projects overview: what to fix first without opening each project. Every project shows its
 * worst severity and counts, PHP and Laravel with their support status, the worst finding and
 * when it last reported, as cards or as a table. Search, sort and view sit in the filter row
 * (app-ui `docs/list-page.md`); sorting happens in the browser.
 */
export default function Dashboard({
    projects,
    canViewProjects,
    trend = [],
}: {
    projects: ProjectRow[];
    canViewProjects: boolean;
    trend?: TrendPoint[];
}) {
    const { t } = useTranslation();
    const { can } = useCan();
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<SortField>('severity');
    const [direction, setDirection] = useState<SortDirection>('asc');
    const [view, setView] = useState<DashboardView>('cards');

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return projects
            .filter(
                (project) =>
                    needle === '' ||
                    `${project.name} ${project.slug}`
                        .toLowerCase()
                        .includes(needle),
            )
            .sort(
                (first, second) =>
                    compareProjects(first, second, sort) *
                    (direction === 'asc' ? 1 : -1),
            );
    }, [projects, search, sort, direction]);

    const sortFields: { value: SortField; label: string }[] = [
        { value: 'severity', label: t('dashboard.sort.severity') },
        { value: 'name', label: t('common.name') },
        { value: 'last_report', label: t('dashboard.sort.lastReport') },
    ];

    return (
        <>
            <Head title={t('nav.dashboard')} />
            <PageLayout
                scrollContent
                filters={
                    canViewProjects && projects.length > 0 ? (
                        <FilterSearch
                            value={search}
                            onChange={setSearch}
                            placeholder={t('projects.searchPlaceholder')}
                        />
                    ) : undefined
                }
                filtersRight={
                    canViewProjects && projects.length > 0 ? (
                        <div className="flex items-center gap-1.5">
                            <Select
                                value={sort}
                                onValueChange={(value) =>
                                    setSort(value as SortField)
                                }
                            >
                                <SelectTrigger
                                    aria-label={t('dashboard.sort.label')}
                                    className="w-[9rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    {sortFields.map((field) => (
                                        <SelectItem
                                            key={field.value}
                                            value={field.value}
                                        >
                                            {field.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label={
                                    direction === 'asc'
                                        ? t('common.sortAscending')
                                        : t('common.sortDescending')
                                }
                                onClick={() =>
                                    setDirection(
                                        direction === 'asc' ? 'desc' : 'asc',
                                    )
                                }
                            >
                                {direction === 'asc' ? (
                                    <ArrowUpAZ className="size-4" />
                                ) : (
                                    <ArrowDownAZ className="size-4" />
                                )}
                            </Button>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={view}
                                onValueChange={(value) => {
                                    if (
                                        value === 'cards' ||
                                        value === 'table'
                                    ) {
                                        setView(value);
                                    }
                                }}
                            >
                                <ToggleGroupItem
                                    value="cards"
                                    aria-label={t('dashboard.view.cards')}
                                    className="px-2.5"
                                >
                                    <LayoutGrid className="size-4" />
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="table"
                                    aria-label={t('dashboard.view.table')}
                                    className="px-2.5"
                                >
                                    <List className="size-4" />
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    ) : undefined
                }
            >
                {!canViewProjects ? (
                    <p className="text-sm text-muted-foreground">
                        {t('dashboard.noAccess')}
                    </p>
                ) : projects.length === 0 ? (
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
                ) : visible.length === 0 ? (
                    <PageEmptyState
                        icon={SearchX}
                        title={
                            <EmptyStatus>
                                {t('emptyState.projects.filteredTitle')}
                            </EmptyStatus>
                        }
                        description={t(
                            'emptyState.projects.filteredDescription',
                        )}
                        action={
                            <Button type="button" onClick={() => setSearch('')}>
                                {t('common.clearFilters')}
                            </Button>
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        <FleetOverview projects={projects} trend={trend} />
                        {view === 'table' ? (
                            <ProjectsTable projects={visible} />
                        ) : (
                            <ul className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                                {visible.map((project) => (
                                    <li
                                        key={project.id}
                                        className="xl:col-span-4"
                                    >
                                        <ProjectCard project={project} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </PageLayout>
        </>
    );
}

/**
 * Findings across every project: the daily trend of the last 30 days as stacked bars, and
 * today's totals per severity as stat tiles (externa-core dashboard cards).
 */
function FleetOverview({
    projects,
    trend,
}: {
    projects: ProjectRow[];
    trend: TrendPoint[];
}) {
    const { t } = useTranslation();
    const totals = Object.fromEntries(
        SEVERITIES.map((severity) => [
            severity,
            projects.reduce(
                (total, project) =>
                    total + (project.latest_report?.counts[severity] ?? 0),
                0,
            ),
        ]),
    ) as Record<Severity, number>;
    const total = SEVERITIES.reduce(
        (sum, severity) => sum + totals[severity],
        0,
    );
    const chartData: SeverityChartPoint[] = trend.map((point) => ({
        ...point,
        label: new Date(`${point.date}T00:00:00`).toLocaleDateString(
            undefined,
            { day: 'numeric', month: 'short' },
        ),
    }));

    return (
        <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <Card className="xl:col-span-8">
                <CardHeader className="pb-3">
                    <CardTitle>{t('dashboard.trend.title')}</CardTitle>
                    <CardDescription>
                        {t('dashboard.trend.description')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="h-64">
                    <SeverityStackedBarChart
                        data={chartData}
                        label={t('dashboard.trend.label', { total })}
                    />
                </CardContent>
            </Card>
            <Card className="xl:col-span-4">
                <CardHeader className="pb-3">
                    <CardTitle>{t('dashboard.totals.title')}</CardTitle>
                    <CardDescription>
                        {t('dashboard.totals.description', {
                            count: projects.length,
                        })}
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid grid-cols-3 gap-3">
                    {SEVERITIES.map((severity) => (
                        <div key={severity}>
                            <div className="text-xs text-muted-foreground">
                                {t(`report.severity.${severity}`)}
                            </div>
                            <div
                                className={cn(
                                    'text-2xl font-semibold tabular-nums',
                                    severity === 'critical' &&
                                        totals.critical > 0 &&
                                        'text-destructive',
                                )}
                            >
                                {totals[severity].toLocaleString()}
                            </div>
                        </div>
                    ))}
                    <div>
                        <div className="text-xs text-muted-foreground">
                            {t('dashboard.totals.total')}
                        </div>
                        <div className="text-2xl font-semibold tabular-nums">
                            {total.toLocaleString()}
                        </div>
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Worst severity first (projects with no report last), then name, then last report.
 */
function compareProjects(
    first: ProjectRow,
    second: ProjectRow,
    sort: SortField,
): number {
    if (sort === 'name') {
        return first.name.localeCompare(second.name);
    }

    if (sort === 'last_report') {
        return (
            (Date.parse(second.last_report_at ?? '') || 0) -
            (Date.parse(first.last_report_at ?? '') || 0)
        );
    }

    return severityRank(first) - severityRank(second);
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

/**
 * One project on the overview. The whole card is a link to the project page.
 */
function ProjectCard({ project }: { project: ProjectRow }) {
    const { t } = useTranslation();
    const report = project.latest_report ?? null;

    return (
        <Link
            href={projectsRoutes.show.url(project.slug)}
            className="block h-full rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <Card
                className={cn(
                    'h-full transition-colors hover:bg-accent/40',
                    project.is_stale && 'opacity-60',
                )}
            >
                <CardHeader className="pb-3">
                    <div className="flex items-start justify-between gap-2">
                        <CardTitle className="truncate">
                            {project.name}
                        </CardTitle>
                        {report ? (
                            <SeverityBadge severity={report.worst_severity} />
                        ) : null}
                    </div>
                    <CardDescription>
                        {report
                            ? t('dashboard.lastReport', {
                                  date: new Date(
                                      report.received_at,
                                  ).toLocaleString(),
                              })
                            : t('dashboard.noReports')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    {report?.runtime ? (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            {OVERVIEW_RUNTIMES.map((name) =>
                                report.runtime?.[name] ? (
                                    <span
                                        key={name}
                                        className="inline-flex items-center gap-1.5 text-sm"
                                    >
                                        <span className="font-mono text-xs">
                                            {runtimeLabel(
                                                name,
                                                report.runtime[name],
                                                t,
                                            )}
                                        </span>
                                        <RuntimeSupportBadge
                                            runtime={report.runtime[name]}
                                        />
                                    </span>
                                ) : null,
                            )}
                        </div>
                    ) : null}
                    {report ? <SeverityCounts counts={report.counts} /> : null}
                    {report?.top_finding ? (
                        <p className="line-clamp-2 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                                {report.top_finding.subject}
                            </span>
                            {' — '}
                            {report.top_finding.message}
                        </p>
                    ) : null}
                    {project.is_stale || !project.is_enabled ? (
                        <div className="flex flex-wrap gap-1">
                            {project.is_stale ? (
                                <Badge variant="muted">
                                    {t('dashboard.stale')}
                                </Badge>
                            ) : null}
                            {!project.is_enabled ? (
                                <Badge variant="muted">
                                    {t('projects.disabled')}
                                </Badge>
                            ) : null}
                        </div>
                    ) : null}
                </CardContent>
            </Card>
        </Link>
    );
}

/**
 * The same overview as a table, one project per row.
 */
function ProjectsTable({ projects }: { projects: ProjectRow[] }) {
    const { t } = useTranslation();

    return (
        <TablePanel>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{t('common.name')}</TableHead>
                        <TableHead>{t('report.worstSeverity')}</TableHead>
                        <TableHead>{t('report.findings')}</TableHead>
                        {OVERVIEW_RUNTIMES.map((name) => (
                            <TableHead key={name}>
                                {t(`report.runtime.${name}`)}
                            </TableHead>
                        ))}
                        <TableHead className="text-right">
                            {t('projects.lastReport')}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {projects.map((project) => {
                        const report = project.latest_report ?? null;

                        return (
                            <TableRow key={project.id}>
                                <TableCell className="max-w-[16rem]">
                                    <Link
                                        href={projectsRoutes.show.url(
                                            project.slug,
                                        )}
                                        className="block truncate font-medium underline-offset-4 hover:underline"
                                    >
                                        {project.name}
                                    </Link>
                                    <div className="truncate font-mono text-xs text-muted-foreground">
                                        {project.slug}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {report ? (
                                        <SeverityBadge
                                            severity={report.worst_severity}
                                        />
                                    ) : (
                                        <span className="text-sm text-muted-foreground">
                                            {t('dashboard.noReports')}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {report ? (
                                        <SeverityCounts
                                            counts={report.counts}
                                        />
                                    ) : null}
                                </TableCell>
                                {OVERVIEW_RUNTIMES.map((name) => {
                                    const runtime =
                                        report?.runtime?.[name] ?? null;

                                    return (
                                        <TableCell key={name}>
                                            {runtime ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <span className="font-mono text-xs">
                                                        {runtime.version ??
                                                            runtime.cycle}
                                                    </span>
                                                    <RuntimeSupportBadge
                                                        runtime={runtime}
                                                    />
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                    );
                                })}
                                <TableCell
                                    className={cn(
                                        'text-right tabular-nums',
                                        project.is_stale &&
                                            'text-muted-foreground',
                                    )}
                                >
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
        </TablePanel>
    );
}

Dashboard.layout = (page: ReactNode) => (
    <AppLayout breadcrumbs={[{ title: 'Dashboard', href: dashboard() }]}>
        {page}
    </AppLayout>
);
