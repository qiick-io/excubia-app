import { Head, router } from '@inertiajs/react';
import { Activity, RefreshCw } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import { AiUsageView } from '@/components/reports/ai-usage-view';
import { AlertsView } from '@/components/reports/alerts-view';
import type { AssigneeOption } from '@/components/reports/assignee-select';
import { ExceptionsView } from '@/components/reports/exceptions-view';
import type { FindingFix } from '@/components/reports/fix-actions';
import { LicensesView } from '@/components/reports/licenses-view';
import { RuntimeView } from '@/components/reports/runtime-view';
import { ScheduleView } from '@/components/reports/schedule-view';
import {
    DEFAULT_TIME_RANGE,
    TimeRangePicker,
} from '@/components/reports/time-range-picker';
import { Button } from '@/components/ui/button';
import { ProjectPinButton } from '@/components/project-pin-button';
import AppLayout from '@/layouts/app-layout';
import projectsRoutes from '@/routes/projects';
import type {
    AiUsageSummary,
    BreadcrumbItem,
    ExceptionGroupRow,
    ExceptionsOverview,
    HealthReportBody,
    HealthReportSummary,
    IssueRow,
    LifecycleRow,
    LiveHealthCheck,
    ProjectAlerts,
    ProjectRow,
    ReportHealth,
    RuntimeDeploy,
    RuntimeSummary,
    ScheduledTaskRow,
    ScheduleTimelinePoint,
    TimeRange,
} from '@/types';
import type {
    ProjectView,
    ProjectViewCounts,
} from '@/components/projects/project-tabs';
import { ProjectTabs } from '@/components/projects/project-tabs';
import { SystemProjectBadge } from '@/components/projects/system-project-badge';
import { OverviewView } from '@/components/projects/overview/overview-view';
import { FindingsView } from '@/components/projects/findings-view';
import { PlanView } from '@/components/projects/plan-view';
import { HistoryView } from '@/components/projects/history-view';

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
    // The system project runs its checks on demand; other projects push their own reports.
    const refreshReport = canRefreshReport
        ? () =>
              router.post(
                  projectsRoutes.systemReport.url(project.id),
                  {},
                  { preserveScroll: true },
              )
        : null;

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                <>
                    <ProjectPinButton project={project} />
                    {project.is_system ? <SystemProjectBadge /> : null}
                    {refreshReport !== null ? (
                        <Button type="button" onClick={refreshReport}>
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
                            onRefresh={refreshReport}
                        />
                    )}
                </PageLayout>
            )}
        </AppLayout>
    );
}
