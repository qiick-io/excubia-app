import { Head } from '@inertiajs/react';
import { Activity, ExternalLink } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { PageEmptyState } from '@/components/admin/page-empty-state';
import { PageLayout } from '@/components/layout/page-layout';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
} from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import projectsRoutes from '@/routes/projects';
import type {
    BreadcrumbItem,
    HealthReportBody,
    HealthReportSummary,
    ProjectRow,
    ReportCheck,
} from '@/types';

/**
 * Project page: the findings of the latest report grouped by check (only those that warn or
 * fail, worst first), the upgrade plan, and the summaries of the previous reports.
 */
export default function ProjectShow({
    project,
    report,
    reports,
}: {
    project: ProjectRow;
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={project.name} />
            <PageLayout
                scrollContent
                subheader={
                    <div className="flex flex-wrap items-center gap-2">
                        {latest ? (
                            <SeverityBadge severity={latest.worst_severity} />
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
                <div className="flex flex-col gap-6">
                    {report === null ? (
                        <PageEmptyState
                            icon={Activity}
                            title={t('dashboard.noReports')}
                            description={t('report.empty')}
                        />
                    ) : (
                        <>
                            <section
                                aria-labelledby="checks-heading"
                                className="space-y-3"
                            >
                                <h2
                                    id="checks-heading"
                                    className="text-lg font-medium"
                                >
                                    {t('report.checks')}
                                </h2>
                                {report.checks.map((check) => (
                                    <CheckCard
                                        key={check.checkId}
                                        check={check}
                                    />
                                ))}
                            </section>

                            <section
                                aria-labelledby="plan-heading"
                                className="space-y-3"
                            >
                                <h2
                                    id="plan-heading"
                                    className="text-lg font-medium"
                                >
                                    {t('report.upgradePlan')}
                                </h2>
                                {report.upgradePlan.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('report.noUpgradeSteps')}
                                    </p>
                                ) : (
                                    <ol className="list-decimal space-y-1 pl-6 text-sm">
                                        {report.upgradePlan.map((step) => (
                                            <li key={step.stepNumber}>
                                                {step.message}
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>
                        </>
                    )}

                    <section
                        aria-labelledby="history-heading"
                        className="space-y-3"
                    >
                        <h2
                            id="history-heading"
                            className="text-lg font-medium"
                        >
                            {t('report.history')}
                        </h2>
                        {reports.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('dashboard.noReports')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('report.receivedAt')}
                                        </TableHead>
                                        <TableHead>
                                            {t('report.worstSeverity')}
                                        </TableHead>
                                        <TableHead>
                                            {t('report.findings')}
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
                                                    severity={
                                                        summary.worst_severity
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <SeverityCounts
                                                    counts={summary.counts}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </section>
                </div>
            </PageLayout>
        </AppLayout>
    );
}

/**
 * One check of the report with its findings that warn or fail, worst first.
 */
function CheckCard({ check }: { check: ReportCheck }) {
    const { t } = useTranslation();
    const findings = check.findings
        .filter(
            (finding) => finding.status === 'warn' || finding.status === 'fail',
        )
        .sort(
            (first, second) =>
                SEVERITIES.indexOf(first.severity) -
                SEVERITIES.indexOf(second.severity),
        );

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-center gap-2">
                    <CardTitle>{check.name}</CardTitle>
                    <Badge
                        variant={
                            check.status === 'skipped' ? 'muted' : 'outline'
                        }
                    >
                        {t(`report.status.${check.status}`)}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                {findings.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('report.noIssues')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('report.severityLabel')}
                                </TableHead>
                                <TableHead>{t('report.subject')}</TableHead>
                                <TableHead>{t('report.message')}</TableHead>
                                <TableHead>{t('report.fixHint')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {findings.map((finding, index) => (
                                <TableRow
                                    key={`${finding.subject}-${finding.advisoryId ?? index}`}
                                >
                                    <TableCell>
                                        <SeverityBadge
                                            severity={finding.severity}
                                        />
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {finding.subject}
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        {finding.message}
                                        {finding.evidenceUrl ? (
                                            <a
                                                href={finding.evidenceUrl}
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
                                    <TableCell className="font-mono text-xs whitespace-normal">
                                        {finding.fixHint}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
