import type { ColumnDef } from '@tanstack/react-table';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Bug,
    CalendarClock,
    CircleCheck,
    ExternalLink,
    Gauge,
    History,
    Info,
    LayoutGrid,
    ListChecks,
    ListOrdered,
    Package,
    RefreshCw,
    Rocket,
    Scale,
    SearchX,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    DataTable,
    dataTableSelectColumn,
} from '@/components/admin/data-table';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageLayout } from '@/components/layout/page-layout';
import { AiUsageView } from '@/components/reports/ai-usage-view';
import { ApplicationSection } from '@/components/reports/application-section';
import { AlertsView } from '@/components/reports/alerts-view';
import {
    CHECK_CATEGORIES,
    CHECK_STATUS_VARIANTS,
    checkLabel,
    FindingDrawer,
} from '@/components/reports/check-details';
import {
    AssignedToMeToggle,
    isAssignedToMeLinked,
    useCurrentUserId,
    type AssigneeOption,
} from '@/components/reports/assignee-select';
import { ExceptionsView } from '@/components/reports/exceptions-view';
import {
    formatDateTime,
    LifecycleBadges,
} from '@/components/reports/finding-lifecycle';
import type { FindingFix } from '@/components/reports/fix-actions';
import {
    combinedPrompt,
    FixActions,
    useCopyWithToast,
} from '@/components/reports/fix-actions';
import {
    DependenciesCard,
    InfrastructureCard,
} from '@/components/reports/health-cards';
import { LicensesView } from '@/components/reports/licenses-view';
import { ReleaseLabel, shortRelease } from '@/components/reports/release-label';
import { RuntimeView } from '@/components/reports/runtime-view';
import { ScheduleView } from '@/components/reports/schedule-view';
import {
    DEFAULT_TIME_RANGE,
    TimeRangePicker,
} from '@/components/reports/time-range-picker';
import {
    RUNTIMES,
    runtimeLabel,
    RuntimeSupportBadge,
    supportStatus,
} from '@/components/reports/runtime-support';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
    severitySummary,
} from '@/components/reports/severity-badge';
import { SeverityStackedBarChart } from '@/components/reports/severity-chart';
import { StatCard, trendOf } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import {
    Card,
    CardAction,
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { GroupedViewTabs } from '@/components/grouped-view-tabs';
import { ProjectPinButton } from '@/components/project-pin-button';
import type {
    ViewTabGroup,
    ViewTabSeverity,
} from '@/components/grouped-view-tabs';
import { InboxSavedViews } from '@/components/inbox-list';
import type { InboxSavedView } from '@/components/inbox-list';
import { useDrawerDeepLink } from '@/hooks/use-drawer-deep-link';
import {
    getQueryParam,
    locationPageUrl,
    patchLocationQuery,
} from '@/lib/admin-query-params';
import { isFirstSeenWithinLastDay, savedViewHref } from '@/lib/inbox-views';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type {
    AiUsageSummary,
    BreadcrumbItem,
    CheckCategory,
    ExceptionGroupRow,
    ExceptionsOverview,
    HealthReportBody,
    HealthReportSummary,
    IssueRow,
    LifecycleRow,
    LiveHealthCheck,
    ProjectAlerts,
    ProjectRow,
    ReportFinding,
    ReportHealth,
    RuntimeDeploy,
    RuntimeSummary,
    ScheduledTaskRow,
    ScheduleTimelinePoint,
    Severity,
    TimeRange,
} from '@/types';

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

type ProjectView = (typeof VIEWS)[number];

/**
 * Project page: an overview of the latest report (runtimes and their support, findings per
 * severity, the report itself), the findings table with filters, the upgrade plan and the
 * report history. The views are shadcn line tabs under the header, each its own URL
 * (`/projects/{slug}/{view}`), so links, back and bookmarks work; the header keeps the actions.
 */
export default function ProjectShow({
    project,
    view,
    report,
    reports,
    findingFixes = {},
    issues = [],
    findings = [],
    canDismissFindings = false,
    health = null,
    liveHealth = [],
    range = DEFAULT_TIME_RANGE,
    runtime = null,
    ai = null,
    exceptions = [],
    exceptionsOverview = null,
    hasExceptions = false,
    schedule = [],
    scheduleTimeline = [],
    deploys = [],
    alerts = null,
    canManageAlerts = false,
    canManageExceptions = false,
    assignees = [],
    canRefreshReport = false,
    viewCounts,
}: {
    /** Open items behind the Exceptions and Findings tabs (#275), for the tab markers. */
    viewCounts?: ProjectViewCounts;
    /** Who an exception group or a finding may be assigned to (#254). */
    assignees?: AssigneeOption[];
    /** The time range of the runtime, exceptions and schedule tabs (#113). */
    range?: TimeRange;
    /** Alert history, mutes and maintenance windows (#114), on the Alerts tab only. */
    alerts?: ProjectAlerts | null;
    canManageAlerts?: boolean;
    runtime?: RuntimeSummary | null;
    /** AI usage and estimated cost (#129), on the AI tab only. */
    ai?: AiUsageSummary | null;
    exceptions?: ExceptionGroupRow[];
    /** Exceptions of the overview's Application section (#250). */
    exceptionsOverview?: ExceptionsOverview | null;
    hasExceptions?: boolean;
    /** Scheduled tasks and their status (#110), on the Schedule tab only. */
    schedule?: ScheduledTaskRow[];
    scheduleTimeline?: ScheduleTimelinePoint[];
    /** Deploys of the range, for the Exceptions and Schedule charts (#106). */
    deploys?: RuntimeDeploy[];
    canManageExceptions?: boolean;
    canRefreshReport?: boolean;
    /** Ready command and prompt per finding, keyed by `checkId|subject|advisoryId` (#78). */
    findingFixes?: Record<string, FindingFix>;
    /** The findings that warn or fail, problems first, with their check group (#109). */
    issues?: IssueRow[];
    /** The finding lifecycle (#18), on the Findings tab only. */
    findings?: LifecycleRow[];
    canDismissFindings?: boolean;
    /** Health summary of the latest report (#108, #109). */
    health?: ReportHealth | null;
    /** Minute-level health per check and server (#115), on the overview only. */
    liveHealth?: LiveHealthCheck[];
    project: ProjectRow;
    /** The tab, from the URL (`/projects/{slug}` or `/projects/{slug}/{view}`). */
    view: ProjectView;
    report: HealthReportBody | null;
    reports: HealthReportSummary[];
}) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('projects.title'), href: projectsRoutes.index.url() },
            {
                title: project.name,
                href: projectsRoutes.show.url(project.slug),
            },
        ],
        [project.name, project.slug, t],
    );

    const latest = project.latest_report ?? null;

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                <>
                    <ProjectPinButton project={project} />
                    {project.is_system ? <SystemProjectBadge /> : null}
                    {canRefreshReport ? (
                        <Button
                            type="button"
                            onClick={() =>
                                router.post(
                                    projectsRoutes.systemReport.url(project.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw className="size-4" />
                            {t('report.refresh')}
                        </Button>
                    ) : null}
                </>
            }
        >
            <Head title={project.name} />
            <ProjectTabs slug={project.slug} view={view} counts={viewCounts} />
            {view === 'findings' && report !== null ? (
                <FindingsView
                    rows={findings}
                    fixes={findingFixes}
                    projectId={project.id}
                    latestReceivedAt={latest?.received_at ?? null}
                    canDismiss={canDismissFindings}
                    assignees={assignees}
                />
            ) : view === 'schedule' ? (
                <ScheduleView
                    slug={project.slug}
                    tasks={schedule}
                    timeline={scheduleTimeline}
                    range={range}
                    deploys={deploys}
                />
            ) : view === 'alerts' && alerts !== null ? (
                <AlertsView
                    projectId={project.id}
                    alerts={alerts}
                    canManage={canManageAlerts}
                />
            ) : view === 'exceptions' ? (
                <ExceptionsView
                    projectId={project.id}
                    slug={project.slug}
                    groups={exceptions}
                    hasExceptions={hasExceptions}
                    range={range}
                    deploys={deploys}
                    canManage={canManageExceptions}
                    assignees={assignees}
                />
            ) : (
                <PageLayout
                    scrollContent
                    filtersRight={
                        (view === 'runtime' && runtime !== null) ||
                        (view === 'ai' && ai !== null) ? (
                            <TimeRangePicker
                                slug={project.slug}
                                view={view}
                                range={range}
                            />
                        ) : undefined
                    }
                >
                    {view === 'history' ? (
                        <HistoryView reports={reports} />
                    ) : view === 'runtime' ? (
                        <RuntimeView runtime={runtime} slug={project.slug} />
                    ) : view === 'ai' ? (
                        <AiUsageView
                            projectId={project.id}
                            slug={project.slug}
                            ai={ai}
                            canManage={canManageAlerts}
                        />
                    ) : report === null || latest === null ? (
                        <PageEmptyState
                            icon={Activity}
                            title={t('dashboard.noReports')}
                            description={
                                project.is_system
                                    ? t('report.systemEmpty')
                                    : t('report.empty')
                            }
                        />
                    ) : view === 'plan' ? (
                        <PlanView report={report} />
                    ) : view === 'licenses' ? (
                        <LicensesView licenses={health?.licenses ?? null} />
                    ) : (
                        <OverviewView
                            project={project}
                            latest={latest}
                            previous={reports[1] ?? null}
                            deploys={reports.filter(
                                (summary) => summary.deployed_at !== null,
                            )}
                            issues={issues}
                            health={health}
                            liveHealth={liveHealth}
                            fixes={findingFixes}
                            runtime={runtime}
                            exceptions={exceptions}
                            exceptionsOverview={exceptionsOverview}
                        />
                    )}
                </PageLayout>
            )}
        </AppLayout>
    );
}

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

/**
 * "System project" in the header, with what it means in a tooltip (keyboard reachable: the
 * badge is a button).
 */
function SystemProjectBadge() {
    const { t } = useTranslation();

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge variant="info" asChild>
                    <button type="button">
                        <Info />
                        {t('report.overview.systemProject')}
                    </button>
                </Badge>
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">
                {t('report.overview.systemProjectHint')}
            </TooltipContent>
        </Tooltip>
    );
}

type ProjectViewCounts = {
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

function ProjectTabs({
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

type AttentionItem =
    | {
          kind: 'finding';
          key: string;
          finding: IssueRow;
          fix: FindingFix | null;
      }
    | { kind: 'exception'; key: string; group: ExceptionGroupRow };

/** Rows of each kind the "Needs attention" card shows; the tabs hold the rest. */
const ATTENTION_FINDINGS = 8;
const ATTENTION_EXCEPTIONS = 3;

/**
 * Problems first: a row of stat cards (shadcn `dashboard-01`, as the dashboard) for findings,
 * checks, runtimes and, when the project pushes them, exceptions and requests; then what needs
 * attention, worst first, with its fix; then the runtimes and every check.
 */
function OverviewView({
    project,
    latest,
    previous,
    deploys,
    issues,
    health,
    liveHealth,
    fixes,
    runtime,
    exceptions,
    exceptionsOverview,
}: {
    project: ProjectRow;
    latest: HealthReportSummary;
    previous: HealthReportSummary | null;
    /** Reports sent with `excubia push --deploy`, newest first (#106). */
    deploys: HealthReportSummary[];
    issues: IssueRow[];
    health: ReportHealth | null;
    liveHealth: LiveHealthCheck[];
    fixes: Record<string, FindingFix>;
    runtime: RuntimeSummary | null;
    exceptions: ExceptionGroupRow[];
    exceptionsOverview: ExceptionsOverview | null;
}) {
    const { t, i18n } = useTranslation();

    // Already problems-first: failing infrastructure, then by severity (#109).
    const findings = issues;
    const openExceptions = exceptions.filter(
        (group) => group.status === 'open',
    );
    const items: AttentionItem[] = [
        ...findings
            .slice(0, ATTENTION_FINDINGS)
            .map((finding): AttentionItem => ({
                kind: 'finding',
                key: findingKey(finding),
                finding,
                fix: fixes[findingKey(finding)] ?? null,
            })),
        ...openExceptions
            .slice(0, ATTENTION_EXCEPTIONS)
            .map((group): AttentionItem => ({
                kind: 'exception',
                key: `exception-${group.id}`,
                group,
            })),
    ];

    const total = SEVERITIES.reduce(
        (sum, severity) => sum + latest.counts[severity],
        0,
    );
    const previousTotal = previous
        ? SEVERITIES.reduce(
              (sum, severity) => sum + previous.counts[severity],
              0,
          )
        : null;
    const checksTotal =
        latest.checks.pass +
        latest.checks.warn +
        latest.checks.fail +
        latest.checks.skipped;
    const checksSummary = (['fail', 'warn', 'skipped'] as const)
        .filter((status) => latest.checks[status] > 0)
        .map((status) =>
            t(`report.overview.checkCount.${status}`, {
                count: latest.checks[status],
            }),
        );

    const usedRuntimes = RUNTIMES.flatMap((name) => {
        const version = latest.runtime?.[name] ?? null;

        return version === null ? [] : [{ name, version }];
    });
    const supportedRuntimes = usedRuntimes.filter(
        (item) =>
            supportStatus(item.version.security_support_ends_on) ===
            'supported',
    );
    const notSupported = usedRuntimes.find(
        (item) =>
            supportStatus(item.version.security_support_ends_on) !==
            'supported',
    );
    const nextEnd = usedRuntimes
        .filter((item) => item.version.security_support_ends_on)
        .sort((first, second) =>
            String(first.version.security_support_ends_on).localeCompare(
                String(second.version.security_support_ends_on),
            ),
        )[0];

    const errorRate =
        runtime && runtime.requests.count > 0
            ? (runtime.requests.errors / runtime.requests.count) * 100
            : 0;
    const lastDeploy = deploys[0] ?? null;
    const currentRelease = latest.release ?? lastDeploy?.release ?? null;
    const hasRelease = currentRelease !== null || lastDeploy !== null;
    const cardCount =
        3 +
        (exceptions.length > 0 ? 1 : 0) +
        (runtime !== null ? 1 : 0) +
        (hasRelease ? 1 : 0);
    const lastSeen = openExceptions[0]?.last_seen_at ?? null;

    return (
        <div className="flex flex-col gap-4">
            <section
                className={cn(
                    'grid grid-cols-1 gap-4 sm:grid-cols-2',
                    STAT_GRID[cardCount],
                )}
            >
                <StatCard
                    label={t('report.overview.findings')}
                    value={total.toLocaleString()}
                    trend={trendOf(total, previousTotal)}
                    headline={severitySummary(latest.counts, t)}
                    context={[
                        t(
                            project.is_stale
                                ? 'report.overview.lastReportStale'
                                : 'report.overview.lastReport',
                            {
                                time: relativeTime(
                                    latest.received_at,
                                    i18n.language,
                                ),
                            },
                        ),
                        project.environment === null
                            ? null
                            : t('report.overview.environment', {
                                  environment: project.environment,
                              }),
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
                <StatCard
                    label={t('report.overview.checks')}
                    value={`${latest.checks.pass}/${checksTotal}`}
                    headline={
                        checksSummary.length === 0
                            ? t('report.overview.allChecksPass')
                            : checksSummary.join(' · ')
                    }
                    context={
                        latest.excubia_version
                            ? t('report.coreVersion', {
                                  version: latest.excubia_version,
                              })
                            : undefined
                    }
                />
                <StatCard
                    label={t('report.overview.runtimes')}
                    value={
                        latest.runtime === null
                            ? '—'
                            : `${supportedRuntimes.length}/${usedRuntimes.length}`
                    }
                    headline={
                        latest.runtime === null
                            ? t('report.overview.runtimesUnavailable')
                            : notSupported
                              ? t('report.overview.runtimeStatus', {
                                    runtime: runtimeLabel(
                                        notSupported.name,
                                        notSupported.version,
                                        t,
                                    ),
                                    status: t(
                                        `report.support.${supportStatus(notSupported.version.security_support_ends_on)}`,
                                    ).toLowerCase(),
                                })
                              : t('report.overview.allRuntimesSupported')
                    }
                    context={
                        nextEnd
                            ? t('report.overview.nextEnd', {
                                  runtime: runtimeLabel(
                                      nextEnd.name,
                                      nextEnd.version,
                                      t,
                                  ),
                                  date: nextEnd.version
                                      .security_support_ends_on,
                              })
                            : undefined
                    }
                />
                {exceptions.length > 0 ? (
                    <StatCard
                        label={t('dashboard.telemetry.openExceptions')}
                        value={openExceptions.length.toLocaleString()}
                        headline={t('report.overview.events', {
                            count: openExceptions.reduce(
                                (sum, group) => sum + group.count,
                                0,
                            ),
                        })}
                        context={
                            lastSeen
                                ? t('report.overview.lastSeen', {
                                      time: relativeTime(
                                          lastSeen,
                                          i18n.language,
                                      ),
                                  })
                                : t('dashboard.telemetry.noOpenExceptions')
                        }
                    />
                ) : null}
                {runtime !== null ? (
                    <StatCard
                        label={t('report.overview.requests', {
                            count: runtime.hours,
                        })}
                        value={runtime.requests.count.toLocaleString()}
                        headline={t('dashboard.telemetry.errors', {
                            count: runtime.requests.errors,
                            rate: errorRate.toFixed(errorRate < 1 ? 2 : 1),
                        })}
                        context={
                            runtime.requests.averageMs !== null
                                ? t('report.overview.averageMs', {
                                      ms: runtime.requests.averageMs,
                                  })
                                : undefined
                        }
                    />
                ) : null}
                {hasRelease ? (
                    <StatCard
                        label={t('report.overview.release')}
                        value={
                            currentRelease === null
                                ? '—'
                                : shortRelease(currentRelease)
                        }
                        headline={
                            lastDeploy?.deployed_at
                                ? t('report.overview.lastDeploy', {
                                      time: relativeTime(
                                          lastDeploy.deployed_at,
                                          i18n.language,
                                      ),
                                  })
                                : t('report.overview.noDeploy')
                        }
                        context={
                            deploys.length > 0
                                ? t('report.overview.deployCount', {
                                      count: deploys.length,
                                  })
                                : undefined
                        }
                    />
                ) : null}
            </section>

            {liveHealth.length > 0 ? (
                <LiveHealthCard checks={liveHealth} />
            ) : null}

            <NeedsAttentionCard
                slug={project.slug}
                items={items}
                total={findings.length + openExceptions.length}
            />

            {health !== null ? (
                <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <InfrastructureCard
                        health={health}
                        className="xl:col-span-7"
                    />
                    <DependenciesCard
                        slug={project.slug}
                        health={health}
                        className="xl:col-span-5"
                    />
                </section>
            ) : null}

            <ApplicationSection
                slug={project.slug}
                runtime={runtime}
                exceptions={exceptionsOverview}
            />

            {/* The checks list is long: the runtimes card keeps its own height (#194). */}
            <section className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
                <RuntimesCard latest={latest} className="xl:col-span-5" />
                {health !== null ? (
                    <ChecksCard health={health} className="xl:col-span-7" />
                ) : null}
            </section>
        </div>
    );
}

/** Static classes, so Tailwind sees them: one column per stat card on wide screens. */
const STAT_GRID: Record<number, string> = {
    3: 'xl:grid-cols-3',
    4: 'xl:grid-cols-4',
    5: 'xl:grid-cols-5',
    6: 'xl:grid-cols-3',
};

/**
 * What to fix first: the worst findings, then the open exceptions seen most recently, each
 * with its ready command or prompt. The tabs list everything.
 */
function NeedsAttentionCard({
    slug,
    items,
    total,
}: {
    slug: string;
    items: AttentionItem[];
    total: number;
}) {
    const { t } = useTranslation();
    const findingsUrl = projectsRoutes.view.url({
        project: slug,
        view: 'findings',
    });
    const exceptionsUrl = projectsRoutes.view.url({
        project: slug,
        view: 'exceptions',
    });

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.overview.attention.title')}</CardTitle>
                <CardDescription>
                    {t('report.overview.attention.description', {
                        shown: items.length,
                        total,
                    })}
                </CardDescription>
                <CardAction>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={findingsUrl}>
                            {t('report.overview.attention.allFindings')}
                        </Link>
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <PageEmptyState
                        icon={CircleCheck}
                        title={t('report.overview.attention.emptyTitle')}
                        description={t('report.noIssuesDescription')}
                        compact
                        className="border-0"
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[1%]">
                                    {t('report.severityLabel')}
                                </TableHead>
                                <TableHead>
                                    {t('dashboard.topIssues.issue')}
                                </TableHead>
                                <TableHead className="w-[1%] text-right">
                                    {t('fix.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) =>
                                item.kind === 'finding' ? (
                                    <TableRow key={item.key}>
                                        <TableCell>
                                            <SeverityBadge
                                                severity={item.finding.severity}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-[40rem] whitespace-normal">
                                            <Link
                                                href={`${findingsUrl}?finding=${encodeURIComponent(item.key)}`}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {item.finding.subject}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {checkLabel(
                                                    t,
                                                    item.finding.checkId,
                                                    item.finding.checkName,
                                                )}
                                                {item.finding.advisoryId ? (
                                                    <span className="font-mono">
                                                        {' · '}
                                                        {
                                                            item.finding
                                                                .advisoryId
                                                        }
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {item.finding.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {item.fix ? (
                                                <FixActions fix={item.fix} />
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    <TableRow key={item.key}>
                                        <TableCell>
                                            <Badge variant="outline">
                                                <Bug />
                                                {t(
                                                    'report.overview.attention.exception',
                                                )}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="max-w-[40rem] whitespace-normal">
                                            <Link
                                                href={exceptionsUrl}
                                                className="font-mono text-xs font-medium break-all underline-offset-4 hover:underline"
                                            >
                                                {item.group.class}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {item.group.context.name} ·{' '}
                                                {t('report.overview.events', {
                                                    count: item.group.count,
                                                })}
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {item.group.message}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <FixActions fix={item.group.fix} />
                                        </TableCell>
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

/** Each runtime of the latest report, its version and its security support. */
function RuntimesCard({
    latest,
    className,
}: {
    latest: HealthReportSummary;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.runtime.title')}</CardTitle>
                <CardDescription>
                    {t('report.runtime.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {latest.runtime === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.runtime.unavailable')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('report.runtime.name')}
                                </TableHead>
                                <TableHead>
                                    {t('report.runtime.version')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('report.runtime.securityUntil')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('report.runtime.status')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {RUNTIMES.map((name) => {
                                const runtime = latest.runtime?.[name] ?? null;

                                return (
                                    <TableRow key={name}>
                                        <TableCell className="font-medium">
                                            {t(`report.runtime.${name}`)}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {runtime?.version ?? '—'}
                                            {runtime?.cycle ? (
                                                <span className="ml-1 text-muted-foreground">
                                                    ({runtime.cycle})
                                                </span>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {runtime?.security_support_ends_on ??
                                                '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {runtime === null ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {t(
                                                        'report.runtime.notUsed',
                                                    )}
                                                </span>
                                            ) : (
                                                <RuntimeSupportBadge
                                                    runtime={runtime}
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Every check of the latest report by group (infrastructure, configuration, dependencies, then
 * ids this app does not know), its status and how many findings it raised.
 */
function ChecksCard({
    health,
    className,
}: {
    health: ReportHealth;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className={className}>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.checks')}</CardTitle>
                <CardDescription>
                    {t('report.checksDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('report.check')}</TableHead>
                            <TableHead>{t('report.runtime.status')}</TableHead>
                            <TableHead className="text-right">
                                {t('report.findings')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    {health.groups.map((group) => (
                        <TableBody key={group.category}>
                            <TableRow className="bg-muted/50 hover:bg-muted/50">
                                <TableHead
                                    scope="colgroup"
                                    colSpan={2}
                                    className="font-medium text-foreground"
                                >
                                    {t(`checks.category.${group.category}`)}
                                </TableHead>
                                <TableCell className="text-right">
                                    <Badge
                                        variant={
                                            CHECK_STATUS_VARIANTS[group.status]
                                        }
                                    >
                                        {t(`report.status.${group.status}`)}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                            {group.checks.map((check) => (
                                <TableRow key={check.checkId}>
                                    <TableCell>
                                        <div className="font-medium">
                                            {checkLabel(
                                                t,
                                                check.checkId,
                                                check.name,
                                            )}
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {check.checkId}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                CHECK_STATUS_VARIANTS[
                                                    check.status
                                                ]
                                            }
                                        >
                                            {t(`report.status.${check.status}`)}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {check.issues}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    ))}
                </Table>
            </CardContent>
        </Card>
    );
}

/** How often the overview reloads the live health (#115): samples arrive once a minute. */
const LIVE_HEALTH_POLL_MS = 60_000;

/**
 * Live health (#115): the minute-level samples of the core `health` runtime type, per check
 * (failing first) with each server's latest reading and when it last sent one. Reloads only
 * its own prop every minute while the overview is open.
 */
function LiveHealthCard({ checks }: { checks: LiveHealthCheck[] }) {
    const { t, i18n } = useTranslation();

    usePoll(LIVE_HEALTH_POLL_MS, { only: ['liveHealth'] });

    return (
        <Card data-test="live-health">
            <CardHeader className="pb-3">
                <CardTitle>{t('report.liveHealth.title')}</CardTitle>
                <CardDescription>
                    {t('report.liveHealth.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('report.check')}</TableHead>
                            <TableHead>{t('report.runtime.status')}</TableHead>
                            <TableHead>
                                {t('report.liveHealth.servers')}
                            </TableHead>
                            <TableHead className="text-right">
                                {t('report.liveHealth.lastSample')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {checks.map((check) => (
                            <TableRow key={check.checkId}>
                                <TableCell>
                                    <div className="font-medium">
                                        {checkLabel(t, check.checkId)}
                                    </div>
                                    <div className="font-mono text-xs text-muted-foreground">
                                        {check.checkId}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant={
                                            CHECK_STATUS_VARIANTS[check.status]
                                        }
                                    >
                                        {t(`report.status.${check.status}`)}
                                    </Badge>
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    <div className="flex flex-wrap gap-1">
                                        {check.servers.map((server) => (
                                            <Badge
                                                key={server.server}
                                                variant={
                                                    server.isStale
                                                        ? 'outline'
                                                        : CHECK_STATUS_VARIANTS[
                                                              server.status
                                                          ]
                                                }
                                                title={relativeTime(
                                                    server.lastSampleAt,
                                                    i18n.language,
                                                )}
                                            >
                                                {server.isStale
                                                    ? t(
                                                          'report.liveHealth.serverStale',
                                                          {
                                                              server: server.server,
                                                          },
                                                      )
                                                    : t(
                                                          'report.liveHealth.serverStatus',
                                                          {
                                                              server: server.server,
                                                              status: t(
                                                                  `report.status.${server.status}`,
                                                              ),
                                                          },
                                                      )}
                                            </Badge>
                                        ))}
                                    </div>
                                </TableCell>
                                <TableCell className="text-right text-muted-foreground">
                                    {relativeTime(
                                        check.lastSampleAt,
                                        i18n.language,
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/**
 * "5 minutes ago" in the user's language, from an ISO date.
 */
function relativeTime(iso: string, locale: string): string {
    const seconds = (Date.parse(iso) - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'minute');
}

type FindingStatusFilter = LifecycleRow['lifecycle']['status'] | 'all';

const FINDING_STATUS_FILTERS: readonly FindingStatusFilter[] = [
    'open',
    'dismissed',
    'resolved',
    'all',
];

/** The Findings tab's status filter from `?status=` (#276), open by default. */
function findingStatusFromUrl(): FindingStatusFilter {
    const value = getQueryParam(locationPageUrl(), 'status');

    return FINDING_STATUS_FILTERS.includes(value as FindingStatusFilter)
        ? (value as FindingStatusFilter)
        : 'open';
}

/**
 * The findings across reports (#18), problems first, as an admin list: search and filters
 * (lifecycle status, severity, group, check) on the left, the table in a TablePanel (app-ui
 * `docs/list-page.md`), and a detail drawer with the check's explanation, structured details,
 * lifecycle and history (`?finding=<key>` opens it). Open findings include those whose check did
 * not run in the latest report. Filtering happens in the browser: the list is small.
 */
function FindingsView({
    rows,
    fixes,
    projectId,
    latestReceivedAt,
    canDismiss,
    assignees,
}: {
    rows: LifecycleRow[];
    fixes: Record<string, FindingFix>;
    projectId: number;
    latestReceivedAt: string | null;
    canDismiss: boolean;
    assignees: AssigneeOption[];
}) {
    const { t, i18n } = useTranslation();
    const [status, setStatusFilter] =
        useState<FindingStatusFilter>(findingStatusFromUrl);
    const currentUserId = useCurrentUserId();
    const [isMine, setIsMineFilter] = useState(isAssignedToMeLinked);
    const [isNewOnly, setIsNewOnly] = useState(
        () => getQueryParam(locationPageUrl(), 'new') === '24h',
    );
    // The filters live in the URL (#276), so a saved view is a link and a view can be shared.
    const setStatus = (value: FindingStatusFilter): void => {
        setStatusFilter(value);
        patchLocationQuery({ status: value === 'open' ? null : value });
    };
    const setIsMine = (value: boolean): void => {
        setIsMineFilter(value);
        patchLocationQuery({ assigned: value ? 'me' : null });
    };
    const copyWithToast = useCopyWithToast();
    const [selected, setSelected] = useState<string[]>([]);
    const [search, setSearch] = useState('');
    const [severity, setSeverity] = useState<Severity | 'all'>('all');
    const [category, setCategory] = useState<CheckCategory | 'all'>('all');
    const [checkId, setCheckId] = useState('all');
    const [openKey, setOpenKey] = useState<string | null>(null);
    const openFinding = rows.find((row) => findingKey(row) === openKey) ?? null;
    const { syncEdit, syncClosed } = useDrawerDeepLink({
        editParam: 'finding',
        onEdit: setOpenKey,
    });

    const checks = useMemo(
        () =>
            [
                ...new Map(
                    rows.map((row) => [
                        row.checkId,
                        checkLabel(t, row.checkId, row.checkName),
                    ]),
                ),
            ].map(([id, name]) => ({ id, name })),
        [rows, t],
    );
    const categories = CHECK_CATEGORIES.filter((item) =>
        rows.some((row) => row.category === item),
    );

    const needle = search.trim().toLowerCase();
    const filtered = rows.filter(
        (row) =>
            (status === 'all' || row.lifecycle.status === status) &&
            (!isMine || row.lifecycle.assignee?.id === currentUserId) &&
            (!isNewOnly ||
                isFirstSeenWithinLastDay(row.lifecycle.firstSeenAt)) &&
            (severity === 'all' || row.severity === severity) &&
            (category === 'all' || row.category === category) &&
            (checkId === 'all' || row.checkId === checkId) &&
            (needle === '' ||
                `${row.subject} ${row.message} ${row.advisoryId ?? ''}`
                    .toLowerCase()
                    .includes(needle)),
    );
    const hasActiveFilters =
        needle !== '' ||
        (status !== 'open' && status !== 'resolved') ||
        isMine ||
        isNewOnly ||
        severity !== 'all' ||
        category !== 'all' ||
        checkId !== 'all';

    const openRows = rows.filter((row) => row.lifecycle.status === 'open');
    const savedViews: InboxSavedView[] = [
        {
            id: 'open',
            label: t('findings.views.open'),
            href: savedViewHref({}),
            count: openRows.length,
        },
        {
            id: 'mine',
            label: t('findings.views.mine'),
            href: savedViewHref({ assigned: 'me' }),
            count: openRows.filter(
                (row) => row.lifecycle.assignee?.id === currentUserId,
            ).length,
        },
        {
            id: 'new',
            label: t('findings.views.new'),
            href: savedViewHref({ new: '24h' }),
            count: openRows.filter((row) =>
                isFirstSeenWithinLastDay(row.lifecycle.firstSeenAt),
            ).length,
        },
    ];
    const hasOtherFilters =
        status !== 'open' ||
        needle !== '' ||
        severity !== 'all' ||
        category !== 'all' ||
        checkId !== 'all';
    const activeSavedViewId = hasOtherFilters
        ? undefined
        : isMine && !isNewOnly
          ? 'mine'
          : isNewOnly && !isMine
            ? 'new'
            : !isMine && !isNewOnly
              ? 'open'
              : undefined;
    const selectedFindingIds = rows
        .filter((row) => selected.includes(findingKey(row)))
        .map((row) => row.lifecycle.id);
    const canAssignToMe =
        canDismiss &&
        currentUserId !== null &&
        assignees.some((option) => option.id === currentUserId);
    const assignSelectionToMe = (): void => {
        router.put(
            projectsRoutes.findings.bulk.assignee.url(projectId),
            { ids: selectedFindingIds, assigned_user_id: currentUserId },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSelected([]),
            },
        );
    };
    const selectedFixes = selected
        .map((key) => fixes[key])
        .filter((fix): fix is FindingFix => fix !== undefined);

    const findingColumns: ColumnDef<LifecycleRow>[] = [
        dataTableSelectColumn<LifecycleRow>({
            selectAllLabel: t('a11y.selectAll'),
            getRowLabel: (row) => t('fix.select', { subject: row.subject }),
        }),
        {
            id: 'severityLabel',
            header: t('report.severityLabel'),
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <SeverityBadge severity={row.severity} />
                    </>
                );
            },
        },
        {
            id: 'subject',
            header: t('report.subject'),
            meta: { cellClassName: 'max-w-[16rem]' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <button
                            type="button"
                            className="block max-w-full truncate text-left font-medium underline-offset-4 hover:underline"
                            onClick={() => {
                                setOpenKey(findingKey(row));
                                syncEdit(findingKey(row));
                            }}
                        >
                            {detailText(row, 'package') ?? row.subject}
                        </button>
                        <div className="truncate text-xs text-muted-foreground">
                            {checkLabel(t, row.checkId, row.checkName)}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'installed',
            header: t('report.installed'),
            meta: { cellClassName: 'font-mono text-xs' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{detailText(row, 'lockedVersion') ?? '—'}</>;
            },
        },
        {
            id: 'fixedIn',
            header: t('report.fixedIn'),
            meta: { cellClassName: 'font-mono text-xs whitespace-normal' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{detailList(row, 'fixedVersions') ?? '—'}</>;
            },
        },
        {
            id: 'message',
            header: t('report.message'),
            meta: { cellClassName: 'min-w-[16rem] whitespace-normal' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        {row.message}
                        {row.evidenceUrl ? (
                            <a
                                href={row.evidenceUrl}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="ml-1 inline-flex items-center gap-0.5 text-primary underline underline-offset-4"
                            >
                                {t('report.evidence')}
                                <ExternalLink className="size-3" aria-hidden />
                            </a>
                        ) : null}
                    </>
                );
            },
        },
        {
            id: 'fixHint',
            header: t('report.fixHint'),
            meta: {
                cellClassName:
                    'min-w-[14rem] font-mono text-xs whitespace-normal',
            },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return <>{row.fixHint}</>;
            },
        },
        {
            id: 'assignee',
            header: t('assignee.column'),
            meta: { cellClassName: 'min-w-[8rem] text-sm' },
            cell: ({ row: tableRow }) => (
                <>{tableRow.original.lifecycle.assignee?.name ?? '—'}</>
            ),
        },
        {
            id: 'firstSeen',
            header: t('findings.firstSeen'),
            meta: { cellClassName: 'min-w-[10rem] whitespace-normal' },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <time
                            dateTime={row.lifecycle.firstSeenAt}
                            title={formatDateTime(
                                row.lifecycle.firstSeenAt,
                                i18n.language,
                            )}
                            className="text-sm"
                        >
                            {relativeTime(
                                row.lifecycle.firstSeenAt,
                                i18n.language,
                            )}
                        </time>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {row.lifecycle.resolvedAt !== null ? (
                                <Badge variant="success">
                                    {t('findings.fixedOn', {
                                        date: formatDateTime(
                                            row.lifecycle.resolvedAt,
                                            i18n.language,
                                        ),
                                    })}
                                </Badge>
                            ) : null}
                            <LifecycleBadges lifecycle={row.lifecycle} />
                        </div>
                        {row.lifecycle.status !== 'resolved' &&
                        latestReceivedAt !== null &&
                        Date.parse(row.lifecycle.lastSeenAt) <
                            Date.parse(latestReceivedAt) ? (
                            <div className="mt-1 text-xs text-muted-foreground">
                                {t('findings.notInLatest')}
                            </div>
                        ) : null}
                    </>
                );
            },
        },
        {
            id: 'actions',
            header: t('fix.actions'),
            meta: {
                headerClassName: 'w-[1%] text-right',
                cellClassName: 'text-right',
            },
            cell: ({ row: tableRow }) => {
                const row = tableRow.original;

                return (
                    <>
                        <FixActions fix={fixes[findingKey(row)]} />
                    </>
                );
            },
        },
    ];

    return (
        <Drawer
            direction="right"
            open={openFinding !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setOpenKey(null);
                    syncClosed();
                }
            }}
        >
            <PageLayout
                subheader={
                    <InboxSavedViews
                        views={savedViews}
                        activeViewId={activeSavedViewId}
                    />
                }
                filters={
                    <DataTableToolbar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder={t('report.searchFindings')}
                        selectedCount={selected.length}
                        onClearSelection={() => setSelected([])}
                        bulkActions={
                            <>
                                {canAssignToMe ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        data-test="bulk-assign-me"
                                        onClick={assignSelectionToMe}
                                    >
                                        <UserCheck
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('findings.assignToMe')}
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-ai-text"
                                    onClick={() =>
                                        void copyWithToast(
                                            combinedPrompt(selectedFixes),
                                        )
                                    }
                                >
                                    <Sparkles className="size-4" />
                                    {t('fix.copyPromptForSelected', {
                                        count: selectedFixes.length,
                                    })}
                                </Button>
                            </>
                        }
                    />
                }
                filtersRight={
                    selected.length > 0 ? null : (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={status}
                                onValueChange={(value) => {
                                    if (value) {
                                        setStatus(value as FindingStatusFilter);
                                    }
                                }}
                                aria-label={t('findings.statusFilter')}
                            >
                                {(
                                    [
                                        'open',
                                        'dismissed',
                                        'resolved',
                                        'all',
                                    ] as const
                                ).map((item) => (
                                    <ToggleGroupItem
                                        key={item}
                                        value={item}
                                        className="px-2.5"
                                    >
                                        {t(`findings.status.${item}`)}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <AssignedToMeToggle
                                isPressed={isMine}
                                onPressedChange={setIsMine}
                            />
                            <Select
                                value={severity}
                                onValueChange={(value) =>
                                    setSeverity(value as Severity | 'all')
                                }
                            >
                                <SelectTrigger
                                    aria-label={t('report.severityLabel')}
                                    className="w-[8.5rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="all">
                                        {t('report.allSeverities')}
                                    </SelectItem>
                                    {SEVERITIES.map((item) => (
                                        <SelectItem key={item} value={item}>
                                            {t(`report.severity.${item}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={category}
                                onValueChange={(value) =>
                                    setCategory(value as CheckCategory | 'all')
                                }
                            >
                                <SelectTrigger
                                    aria-label={t('checks.categoryLabel')}
                                    className="w-[10rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="all">
                                        {t('checks.allCategories')}
                                    </SelectItem>
                                    {categories.map((item) => (
                                        <SelectItem key={item} value={item}>
                                            {t(`checks.category.${item}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select value={checkId} onValueChange={setCheckId}>
                                <SelectTrigger
                                    aria-label={t('report.check')}
                                    className="w-[12rem]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="all">
                                        {t('report.allChecks')}
                                    </SelectItem>
                                    {checks.map((check) => (
                                        <SelectItem
                                            key={check.id}
                                            value={check.id}
                                        >
                                            {check.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )
                }
            >
                {filtered.length === 0 ? (
                    <PageEmptyState
                        icon={hasActiveFilters ? SearchX : ListChecks}
                        title={
                            <EmptyStatus>
                                {hasActiveFilters
                                    ? t('report.noMatchingFindings')
                                    : status === 'resolved'
                                      ? t('findings.noResolved')
                                      : t('report.noIssues')}
                            </EmptyStatus>
                        }
                        description={
                            hasActiveFilters
                                ? t('report.noMatchingFindingsDescription')
                                : status === 'resolved'
                                  ? t('findings.noResolvedDescription')
                                  : t('report.noIssuesDescription')
                        }
                        action={
                            hasActiveFilters ? (
                                <Button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        setIsMine(false);
                                        setIsNewOnly(false);
                                        patchLocationQuery({ new: null });
                                        setStatus('open');
                                        setSeverity('all');
                                        setCategory('all');
                                        setCheckId('all');
                                    }}
                                >
                                    {t('common.clearFilters')}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <DataTable
                        columns={findingColumns}
                        data={filtered}
                        getRowId={findingKey}
                        rowSelection={Object.fromEntries(
                            selected.map((key) => [key, true]),
                        )}
                        onRowSelectionChange={(updater) => {
                            const current = Object.fromEntries(
                                selected.map((key) => [key, true]),
                            );
                            const next =
                                typeof updater === 'function'
                                    ? updater(current)
                                    : updater;
                            setSelected(
                                Object.keys(next).filter((key) => next[key]),
                            );
                        }}
                    />
                )}
            </PageLayout>
            {openFinding ? (
                <FindingDrawer
                    finding={openFinding}
                    fix={fixes[findingKey(openFinding)]}
                    lifecycle={openFinding.lifecycle}
                    projectId={projectId}
                    canDismiss={canDismiss}
                    assignees={assignees}
                />
            ) : null}
        </Drawer>
    );
}

/** Same key as ReportFindingsService::identity() on the server. */
function findingKey(row: ReportFinding): string {
    return `${row.checkId}|${row.subject}|${row.advisoryId ?? ''}`;
}

function detailText(row: IssueRow, key: string): string | null {
    const value = row.details?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

function detailList(row: IssueRow, key: string): string | null {
    const value = row.details?.[key];

    return Array.isArray(value) && value.length > 0
        ? value.filter((item) => typeof item === 'string').join(', ')
        : null;
}

function PlanView({ report }: { report: HealthReportBody }) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>{t('report.upgradePlan')}</CardTitle>
                <CardDescription>{t('report.planDescription')}</CardDescription>
            </CardHeader>
            <CardContent>
                {report.upgradePlan.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.noUpgradeSteps')}
                    </p>
                ) : (
                    <ol className="list-decimal space-y-2 pl-6 text-sm">
                        {report.upgradePlan.map((step) => (
                            <li key={step.stepNumber}>{step.message}</li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

function HistoryView({ reports }: { reports: HealthReportSummary[] }) {
    const { t } = useTranslation();

    if (reports.length === 0) {
        return (
            <PageEmptyState
                icon={History}
                title={t('dashboard.noReports')}
                description={t('report.empty')}
            />
        );
    }

    // Oldest first, so the chart reads left to right in time.
    const chartData = [...reports].reverse().map((summary) => ({
        label: new Date(summary.received_at).toLocaleString(undefined, {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        }),
        ...summary.counts,
    }));
    const latestTotal = SEVERITIES.reduce(
        (total, severity) => total + reports[0].counts[severity],
        0,
    );

    const reportColumns: ColumnDef<HealthReportSummary>[] = [
        {
            id: 'receivedAt',
            header: t('report.receivedAt'),
            meta: { cellClassName: 'tabular-nums' },
            cell: ({ row }) => {
                const summary = row.original;

                return <>{new Date(summary.received_at).toLocaleString()}</>;
            },
        },
        {
            id: 'worstSeverity',
            header: t('report.worstSeverity'),
            cell: ({ row }) => {
                const summary = row.original;

                return (
                    <>
                        <SeverityBadge severity={summary.worst_severity} />
                    </>
                );
            },
        },
        {
            id: 'findings',
            header: t('report.findings'),
            cell: ({ row }) => {
                const summary = row.original;

                return (
                    <>
                        <SeverityCounts counts={summary.counts} />
                    </>
                );
            },
        },
        {
            id: 'release',
            header: t('report.release'),
            cell: ({ row }) => {
                const summary = row.original;

                return (
                    <>
                        <div className="flex items-center gap-2 text-xs">
                            {summary.release === null ? (
                                <span className="text-muted-foreground">—</span>
                            ) : (
                                <ReleaseLabel release={summary.release} />
                            )}
                            {summary.deployed_at !== null ? (
                                <Badge variant="info">
                                    <Rocket />
                                    {t('report.deploy')}
                                </Badge>
                            ) : null}
                        </div>
                    </>
                );
            },
        },
        {
            id: 'coreVersionShort',
            header: t('report.coreVersionShort'),
            meta: {
                headerClassName: 'text-right',
                cellClassName:
                    'text-right font-mono text-xs text-muted-foreground',
            },
            cell: ({ row }) => {
                const summary = row.original;

                return <>{summary.excubia_version ?? '—'}</>;
            },
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle>{t('report.trend.title')}</CardTitle>
                    <CardDescription>
                        {t('report.trend.description')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="h-64">
                    <SeverityStackedBarChart
                        data={chartData}
                        label={t('report.trend.label', {
                            count: reports.length,
                            total: latestTotal,
                        })}
                    />
                </CardContent>
            </Card>
            <DataTable
                columns={reportColumns}
                data={reports}
                getRowId={(summary) => String(summary.id)}
            />
        </div>
    );
}
