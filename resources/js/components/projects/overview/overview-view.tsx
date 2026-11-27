import { Clock, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { ApplicationSection } from '@/components/reports/application-section';
import type { FindingFix } from '@/components/reports/fix-actions';
import {
    DependenciesCard,
    InfrastructureCard,
} from '@/components/reports/health-cards';
import { ReleaseLabel } from '@/components/reports/release-label';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type {
    ExceptionGroupRow,
    ExceptionsOverview,
    HealthReportSummary,
    IssueRow,
    LiveHealthCheck,
    ProjectRow,
    ReportHealth,
    RuntimeSummary,
} from '@/types';
import type { AttentionItem } from '@/components/projects/overview/needs-attention-card';
import { NeedsAttentionCard } from '@/components/projects/overview/needs-attention-card';
import { OverviewBoard } from '@/components/projects/overview/overview-board';
import type { DashboardHistory } from '@/types/dashboard';
import { RuntimesCard } from '@/components/projects/overview/runtimes-card';
import { ChecksCard } from '@/components/projects/overview/checks-card';
import { LiveHealthCard } from '@/components/projects/overview/live-health-card';
import { relativeTime, findingKey, groupFindings } from '@/lib/report-format';

/**
 * Rows of each kind the "Needs attention" card shows; the tabs hold the rest. Findings that
 * share a check and a message count as one row (#291).
 */
const ATTENTION_FINDINGS = 8;
const ATTENTION_EXCEPTIONS = 3;

/**
 * Problems first: a warning when the report is stale (#292), since every number below is as old
 * as the report; the Board KPIs for findings,
 * checks, runtimes and, when the project pushes them, exceptions and requests; then what needs
 * attention, worst first, with its fix; then the infrastructure, dependencies and runtimes
 * next to the checks that did not pass; then the application's routes, jobs and exceptions.
 */
export function OverviewView({
    project,
    latest,
    kpiHistory,
    deploys,
    issues,
    health,
    liveHealth,
    fixes,
    runtime,
    exceptions,
    exceptionsOverview,
    onRefresh,
}: {
    project: ProjectRow;
    latest: HealthReportSummary;
    kpiHistory: DashboardHistory;
    /** Reports sent with `excubia push --deploy`, newest first (#106). */
    deploys: HealthReportSummary[];
    issues: IssueRow[];
    health: ReportHealth | null;
    liveHealth: LiveHealthCheck[];
    fixes: Record<string, FindingFix>;
    runtime: RuntimeSummary | null;
    exceptions: ExceptionGroupRow[];
    exceptionsOverview: ExceptionsOverview | null;
    /** Runs the checks again; null when the user cannot (only the system project refreshes). */
    onRefresh: (() => void) | null;
}) {
    const { t, i18n } = useTranslation();

    // Already problems-first: failing infrastructure, then by severity (#109).
    const findings = issues;
    const openExceptions = exceptions.filter(
        (group) => group.status === 'open',
    );
    const findingGroups = groupFindings(findings);
    const items: AttentionItem[] = [
        ...findingGroups
            .slice(0, ATTENTION_FINDINGS)
            .map(([finding, ...others]): AttentionItem =>
                others.length === 0
                    ? {
                          kind: 'finding',
                          key: findingKey(finding),
                          finding,
                          fix: fixes[findingKey(finding)] ?? null,
                      }
                    : {
                          kind: 'group',
                          key: `group-${findingKey(finding)}`,
                          findings: [finding, ...others],
                      },
            ),
        ...openExceptions
            .slice(0, ATTENTION_EXCEPTIONS)
            .map((group): AttentionItem => ({
                kind: 'exception',
                key: `exception-${group.id}`,
                group,
            })),
    ];

    const lastDeploy = deploys[0] ?? null;
    const currentRelease = latest.release ?? lastDeploy?.release ?? null;
    const reportAge = relativeTime(latest.received_at, i18n.language);

    return (
        <div className="flex flex-col gap-4">
            {project.is_stale ? (
                <Alert variant="warning">
                    <Clock />
                    <AlertTitle>{t('report.overview.stale.title')}</AlertTitle>
                    <AlertDescription>
                        <p>
                            {t(
                                onRefresh === null
                                    ? 'report.overview.stale.descriptionPush'
                                    : 'report.overview.stale.description',
                                { time: reportAge },
                            )}
                        </p>
                        {onRefresh !== null ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-1"
                                onClick={onRefresh}
                            >
                                <RefreshCw className="size-3.5" />
                                {t('report.refresh')}
                            </Button>
                        ) : null}
                    </AlertDescription>
                </Alert>
            ) : null}
            <OverviewBoard
                latest={latest}
                history={kpiHistory}
                hasExceptions={exceptions.length > 0}
                runtime={runtime}
            />

            {(project.environment || latest.excubia_version) && (
                <p className="text-sm text-muted-foreground">
                    {[
                        project.environment &&
                            t('report.overview.environment', {
                                environment: project.environment,
                            }),
                        latest.excubia_version &&
                            t('report.coreVersion', {
                                version: latest.excubia_version,
                            }),
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}

            {currentRelease !== null || lastDeploy !== null ? (
                <p className="flex flex-wrap items-baseline gap-x-2 text-sm text-muted-foreground">
                    <span>{t('report.overview.release')}</span>
                    {currentRelease !== null ? (
                        <ReleaseLabel release={currentRelease} />
                    ) : null}
                    {lastDeploy?.deployed_at
                        ? t('report.overview.lastDeploy', {
                              time: relativeTime(
                                  lastDeploy.deployed_at,
                                  i18n.language,
                              ),
                          })
                        : t('report.overview.noDeploy')}
                    {deploys.length > 0 &&
                        t('report.overview.deployCount', {
                            count: deploys.length,
                        })}
                </p>
            ) : null}

            {liveHealth.length > 0 ? (
                <LiveHealthCard checks={liveHealth} />
            ) : null}

            <NeedsAttentionCard
                slug={project.slug}
                items={items}
                total={findingGroups.length + openExceptions.length}
            />

            {/*
             * The short cards stack in one column next to the checks (#329). Both columns are as
             * tall as their content, with no inner scroll and no stretched card (#335). Without a
             * report's health data there are no checks, and the runtimes card takes the whole row.
             */}
            {health !== null ? (
                <section className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
                    <div className="flex flex-col gap-4 xl:col-span-5">
                        <InfrastructureCard health={health} />
                        <DependenciesCard slug={project.slug} health={health} />
                        <RuntimesCard latest={latest} />
                    </div>
                    <ChecksCard health={health} className="xl:col-span-7" />
                </section>
            ) : (
                <RuntimesCard latest={latest} />
            )}

            <ApplicationSection
                slug={project.slug}
                runtime={runtime}
                exceptions={exceptionsOverview}
            />
        </div>
    );
}
