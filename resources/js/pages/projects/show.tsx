import { Head, router } from '@inertiajs/react';
import {
    Activity,
    Bug,
    ExternalLink,
    Gauge,
    History,
    LayoutGrid,
    ListChecks,
    ListOrdered,
    RefreshCw,
    SearchX,
    Sparkles,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { EmptyStatus } from '@/components/empty-status';
import { DataTableToolbar } from '@/components/admin/data-table-toolbar';
import { PageLayout, TablePanel } from '@/components/layout/page-layout';
import { ExceptionsView } from '@/components/reports/exceptions-view';
import type { FindingFix } from '@/components/reports/fix-actions';
import {
    combinedPrompt,
    FixActions,
    useCopyWithToast,
} from '@/components/reports/fix-actions';
import { RuntimeView } from '@/components/reports/runtime-view';
import {
    RUNTIMES,
    RuntimeSupportBadge,
} from '@/components/reports/runtime-support';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
} from '@/components/reports/severity-badge';
import { SeverityStackedBarChart } from '@/components/reports/severity-chart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import projectsRoutes from '@/routes/projects';
import type {
    BreadcrumbItem,
    ExceptionGroupRow,
    HealthReportBody,
    HealthReportSummary,
    ProjectRow,
    ReportFinding,
    RuntimeSummary,
    Severity,
} from '@/types';

const VIEWS = [
    'overview',
    'findings',
    'runtime',
    'exceptions',
    'plan',
    'history',
] as const;

type ProjectView = (typeof VIEWS)[number];

type FindingRow = ReportFinding & { checkName: string };

/**
 * Project page: an overview of the latest report (runtimes and their support, findings per
 * severity, the report itself), the findings table with filters, the upgrade plan and the
 * report history. The view is a segmented control in the header, kept in `?view=` like the
 * externa-core dashboard tabs.
 */
export default function ProjectShow({
    project,
    report,
    reports,
    findingFixes = {},
    runtime = null,
    exceptions = [],
    canManageExceptions = false,
    canRefreshReport = false,
}: {
    runtime?: RuntimeSummary | null;
    exceptions?: ExceptionGroupRow[];
    canManageExceptions?: boolean;
    canRefreshReport?: boolean;
    /** Ready command and prompt per finding, keyed by `checkId|subject|advisoryId` (#78). */
    findingFixes?: Record<string, FindingFix>;
    project: ProjectRow;
    report: HealthReportBody | null;
    reports: HealthReportSummary[];
}) {
    const { t } = useTranslation();
    const [view, setView] = useState<ProjectView>(() => initialView());

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

    const selectView = (next: ProjectView): void => {
        setView(next);

        const url = new URL(window.location.href);

        if (next === 'overview') {
            url.searchParams.delete('view');
        } else {
            url.searchParams.set('view', next);
        }

        window.history.replaceState({}, '', url.pathname + url.search);
    };

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            headerActions={
                <>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={view}
                        onValueChange={(value) => {
                            if (VIEWS.includes(value as ProjectView)) {
                                selectView(value as ProjectView);
                            }
                        }}
                        aria-label={t('report.views.label')}
                    >
                        <ToggleGroupItem value="overview">
                            <LayoutGrid className="size-4" />
                            <span>{t('report.views.overview')}</span>
                        </ToggleGroupItem>
                        <ToggleGroupItem value="findings">
                            <ListChecks className="size-4" />
                            <span>{t('report.views.findings')}</span>
                        </ToggleGroupItem>
                        <ToggleGroupItem value="runtime">
                            <Gauge className="size-4" />
                            <span>{t('report.views.runtime')}</span>
                        </ToggleGroupItem>
                        <ToggleGroupItem value="exceptions">
                            <Bug className="size-4" />
                            <span>{t('report.views.exceptions')}</span>
                        </ToggleGroupItem>
                        <ToggleGroupItem value="plan">
                            <ListOrdered className="size-4" />
                            <span>{t('report.views.plan')}</span>
                        </ToggleGroupItem>
                        <ToggleGroupItem value="history">
                            <History className="size-4" />
                            <span>{t('report.views.history')}</span>
                        </ToggleGroupItem>
                    </ToggleGroup>
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
            {view === 'findings' && report !== null ? (
                <FindingsView report={report} fixes={findingFixes} />
            ) : view === 'exceptions' ? (
                <ExceptionsView
                    projectId={project.id}
                    groups={exceptions}
                    canManage={canManageExceptions}
                />
            ) : (
                <PageLayout
                    scrollContent
                    subheader={
                        <div className="flex flex-wrap items-center gap-2">
                            {latest ? (
                                <SeverityBadge
                                    severity={latest.worst_severity}
                                />
                            ) : null}
                            {project.is_system ? (
                                <Badge variant="info">
                                    {t('projects.system')}
                                </Badge>
                            ) : null}
                            {project.is_stale ? (
                                <Badge variant="muted">
                                    {t('dashboard.stale')}
                                </Badge>
                            ) : null}
                            <span className="text-sm text-muted-foreground">
                                {latest
                                    ? t('dashboard.lastReport', {
                                          date: new Date(
                                              latest.received_at,
                                          ).toLocaleString(),
                                      })
                                    : t('dashboard.noReports')}
                            </span>
                        </div>
                    }
                >
                    {view === 'history' ? (
                        <HistoryView reports={reports} />
                    ) : view === 'runtime' ? (
                        <RuntimeView runtime={runtime} />
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
                    ) : (
                        <OverviewView latest={latest} report={report} />
                    )}
                </PageLayout>
            )}
        </AppLayout>
    );
}

function initialView(): ProjectView {
    if (typeof window === 'undefined') {
        return 'overview';
    }

    const value = new URLSearchParams(window.location.search).get('view');

    return VIEWS.includes(value as ProjectView)
        ? (value as ProjectView)
        : 'overview';
}

/**
 * Flat cards in the 12-column grid, as the externa-core dashboard: runtimes, findings per
 * severity, and the report itself.
 */
function OverviewView({
    latest,
    report,
}: {
    latest: HealthReportSummary;
    report: HealthReportBody;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-4">
            <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                <Card className="xl:col-span-5">
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
                                        const runtime =
                                            latest.runtime?.[name] ?? null;

                                        return (
                                            <TableRow key={name}>
                                                <TableCell className="font-medium">
                                                    {t(
                                                        `report.runtime.${name}`,
                                                    )}
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

                <Card className="xl:col-span-4">
                    <CardHeader className="pb-3">
                        <CardTitle>{t('report.findings')}</CardTitle>
                        <CardDescription>
                            {t('report.findingsDescription')}
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
                                            latest.counts.critical > 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    {latest.counts[severity].toLocaleString()}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card className="xl:col-span-3">
                    <CardHeader className="pb-3">
                        <CardTitle>{t('report.lastReport')}</CardTitle>
                        <CardDescription>
                            {t('report.generatedAt', {
                                date: new Date(
                                    latest.generated_at,
                                ).toLocaleString(),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-3">
                        {(['pass', 'warn', 'fail', 'skipped'] as const).map(
                            (status) => (
                                <div key={status}>
                                    <div className="text-xs text-muted-foreground">
                                        {t(`report.status.${status}`)}
                                    </div>
                                    <div className="text-2xl font-semibold tabular-nums">
                                        {latest.checks[status]}
                                    </div>
                                </div>
                            ),
                        )}
                        {latest.excubia_version ? (
                            <p className="col-span-2 text-xs text-muted-foreground">
                                {t('report.coreVersion', {
                                    version: latest.excubia_version,
                                })}
                            </p>
                        ) : null}
                    </CardContent>
                </Card>
            </section>

            <Card>
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
                                <TableHead>
                                    {t('report.runtime.status')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('report.findings')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.checks.map((check) => (
                                <TableRow key={check.checkId}>
                                    <TableCell>
                                        <div className="font-medium">
                                            {check.name}
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {check.checkId}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                check.status === 'fail'
                                                    ? 'destructive'
                                                    : check.status === 'warn'
                                                      ? 'warning'
                                                      : check.status === 'pass'
                                                        ? 'success'
                                                        : 'muted'
                                            }
                                        >
                                            {t(`report.status.${check.status}`)}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {
                                            check.findings.filter(
                                                (finding) =>
                                                    finding.status === 'warn' ||
                                                    finding.status === 'fail',
                                            ).length
                                        }
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}

/**
 * The findings that warn or fail, as an admin list: search and filters on the left, the
 * table in a TablePanel (app-ui `docs/list-page.md`). Filtering happens in the browser: one
 * report is small.
 */
function FindingsView({
    report,
    fixes,
}: {
    report: HealthReportBody;
    fixes: Record<string, FindingFix>;
}) {
    const { t } = useTranslation();
    const copyWithToast = useCopyWithToast();
    const [selected, setSelected] = useState<string[]>([]);
    const [search, setSearch] = useState('');
    const [severity, setSeverity] = useState<Severity | 'all'>('all');
    const [checkId, setCheckId] = useState('all');

    const rows = useMemo<FindingRow[]>(
        () =>
            report.checks
                .flatMap((check) =>
                    check.findings
                        .filter(
                            (finding) =>
                                finding.status === 'warn' ||
                                finding.status === 'fail',
                        )
                        .map((finding) => ({
                            ...finding,
                            checkName: check.name,
                        })),
                )
                .sort(
                    (first, second) =>
                        SEVERITIES.indexOf(first.severity) -
                        SEVERITIES.indexOf(second.severity),
                ),
        [report],
    );

    const checks = useMemo(
        () =>
            [...new Map(rows.map((row) => [row.checkId, row.checkName]))].map(
                ([id, name]) => ({ id, name }),
            ),
        [rows],
    );

    const needle = search.trim().toLowerCase();
    const filtered = rows.filter(
        (row) =>
            (severity === 'all' || row.severity === severity) &&
            (checkId === 'all' || row.checkId === checkId) &&
            (needle === '' ||
                `${row.subject} ${row.message} ${row.advisoryId ?? ''}`
                    .toLowerCase()
                    .includes(needle)),
    );
    const hasActiveFilters =
        needle !== '' || severity !== 'all' || checkId !== 'all';
    const filteredKeys = filtered.map(findingKey);
    const selectedFixes = selected
        .map((key) => fixes[key])
        .filter((fix): fix is FindingFix => fix !== undefined);

    return (
        <PageLayout
            filters={
                <DataTableToolbar
                    search={search}
                    onSearchChange={setSearch}
                    searchPlaceholder={t('report.searchFindings')}
                    selectedCount={selected.length}
                    onClearSelection={() => setSelected([])}
                    bulkActions={
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
                    }
                />
            }
            filtersRight={
                selected.length > 0 ? null : (
                    <div className="flex items-center gap-1.5">
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
                                    <SelectItem key={check.id} value={check.id}>
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
                                : t('report.noIssues')}
                        </EmptyStatus>
                    }
                    description={
                        hasActiveFilters
                            ? t('report.noMatchingFindingsDescription')
                            : t('report.noIssuesDescription')
                    }
                    action={
                        hasActiveFilters ? (
                            <Button
                                type="button"
                                onClick={() => {
                                    setSearch('');
                                    setSeverity('all');
                                    setCheckId('all');
                                }}
                            >
                                {t('common.clearFilters')}
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <TablePanel>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <Checkbox
                                        checked={
                                            filteredKeys.length > 0 &&
                                            filteredKeys.every((key) =>
                                                selected.includes(key),
                                            )
                                        }
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked === true
                                                    ? filteredKeys
                                                    : [],
                                            )
                                        }
                                        aria-label={t('a11y.selectAll')}
                                    />
                                </TableHead>
                                <TableHead>
                                    {t('report.severityLabel')}
                                </TableHead>
                                <TableHead>{t('report.subject')}</TableHead>
                                <TableHead>{t('report.installed')}</TableHead>
                                <TableHead>{t('report.fixedIn')}</TableHead>
                                <TableHead>{t('report.message')}</TableHead>
                                <TableHead>{t('report.fixHint')}</TableHead>
                                <TableHead className="w-[1%] text-right">
                                    {t('fix.actions')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filtered.map((row, index) => (
                                <TableRow
                                    key={
                                        row.fingerprint ??
                                        `${row.checkId}-${row.subject}-${row.advisoryId ?? index}`
                                    }
                                >
                                    <TableCell>
                                        <Checkbox
                                            checked={selected.includes(
                                                findingKey(row),
                                            )}
                                            onCheckedChange={(checked) =>
                                                setSelected((current) =>
                                                    checked === true
                                                        ? [
                                                              ...current,
                                                              findingKey(row),
                                                          ]
                                                        : current.filter(
                                                              (key) =>
                                                                  key !==
                                                                  findingKey(
                                                                      row,
                                                                  ),
                                                          ),
                                                )
                                            }
                                            aria-label={t('fix.select', {
                                                subject: row.subject,
                                            })}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <SeverityBadge
                                            severity={row.severity}
                                        />
                                    </TableCell>
                                    <TableCell className="max-w-[16rem]">
                                        <div className="truncate font-medium">
                                            {detailText(row, 'package') ??
                                                row.subject}
                                        </div>
                                        <div className="truncate font-mono text-xs text-muted-foreground">
                                            {row.checkName}
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {detailText(row, 'lockedVersion') ??
                                            '—'}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs whitespace-normal">
                                        {detailList(row, 'fixedVersions') ??
                                            '—'}
                                    </TableCell>
                                    <TableCell className="min-w-[16rem] whitespace-normal">
                                        {row.message}
                                        {row.evidenceUrl ? (
                                            <a
                                                href={row.evidenceUrl}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="ml-1 inline-flex items-center gap-0.5 text-primary underline-offset-4 hover:underline"
                                            >
                                                {t('report.evidence')}
                                                <ExternalLink
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                            </a>
                                        ) : null}
                                    </TableCell>
                                    <TableCell className="min-w-[14rem] font-mono text-xs whitespace-normal">
                                        {row.fixHint}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <FixActions
                                            fix={fixes[findingKey(row)]}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TablePanel>
            )}
        </PageLayout>
    );
}

/** Same key as ReportFindingsService::identity() on the server. */
function findingKey(row: ReportFinding): string {
    return `${row.checkId}|${row.subject}|${row.advisoryId ?? ''}`;
}

function detailText(row: FindingRow, key: string): string | null {
    const value = row.details?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

function detailList(row: FindingRow, key: string): string | null {
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
            <TablePanel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('report.receivedAt')}</TableHead>
                            <TableHead>{t('report.worstSeverity')}</TableHead>
                            <TableHead>{t('report.findings')}</TableHead>
                            <TableHead className="text-right">
                                {t('report.coreVersionShort')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {reports.map((summary) => (
                            <TableRow key={summary.id}>
                                <TableCell className="tabular-nums">
                                    {new Date(
                                        summary.received_at,
                                    ).toLocaleString()}
                                </TableCell>
                                <TableCell>
                                    <SeverityBadge
                                        severity={summary.worst_severity}
                                    />
                                </TableCell>
                                <TableCell>
                                    <SeverityCounts counts={summary.counts} />
                                </TableCell>
                                <TableCell className="text-right font-mono text-xs text-muted-foreground">
                                    {summary.excubia_version ?? '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </TablePanel>
        </div>
    );
}
