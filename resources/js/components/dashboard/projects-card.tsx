import { Link, usePoll } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { MAX_PROJECTS_IN_CHART } from '@/components/dashboard/fleet-findings';
import { CHECK_STATUS_VARIANTS } from '@/components/reports/check-details';
import { supportStatus } from '@/components/reports/runtime-support';
import {
    SEVERITIES,
    SeverityBadge,
    SeverityCounts,
} from '@/components/reports/severity-badge';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { findingsCount } from '@/lib/report-format';
import projectsRoutes from '@/routes/projects';
import type { LiveHealthSummary, ProjectRow } from '@/types';
import { useDateFormat } from '@/lib/date-format';

const LIVE_HEALTH_POLL_MS = 60_000;
/**
 * Every project in one compact table, worst first, each linking to its page, with its live
 * health (#143) when it sends health samples. Reloads only `liveHealth` every minute.
 */
export function ProjectsCard({
    projects,
    liveHealth,
}: {
    projects: ProjectRow[];
    liveHealth: Record<string, LiveHealthSummary>;
}) {
    const { t, i18n } = useTranslation();
    const dates = useDateFormat();

    usePoll(LIVE_HEALTH_POLL_MS, { only: ['liveHealth'] });

    const isLargeFleet =
        projects.filter((project) => project.latest_report).length >
        MAX_PROJECTS_IN_CHART;
    const rows = [...projects].sort((first, second) => {
        if (isLargeFleet && first.latest_report && second.latest_report) {
            const byCount =
                findingsCount(second.latest_report.counts) -
                findingsCount(first.latest_report.counts);

            if (byCount !== 0) {
                return byCount;
            }
        }

        return severityRank(first) - severityRank(second);
    });

    return (
        <Card className="xl:col-span-7">
            <CardHeader className="pb-3">
                <CardTitle>{t('dashboard.projects.title')}</CardTitle>
                <CardDescription>
                    {t(
                        isLargeFleet
                            ? 'dashboard.projects.byFindings'
                            : 'dashboard.projects.description',
                    )}
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
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {isLargeFleet && (
                                                        <span className="tabular-nums">
                                                            {findingsCount(
                                                                report.counts,
                                                            ).toLocaleString(
                                                                i18n.language,
                                                            )}
                                                        </span>
                                                    )}
                                                    <SeverityCounts
                                                        counts={report.counts}
                                                    />
                                                </div>
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
                                                ? dates.dateTime(
                                                      project.last_report_at,
                                                  )
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
