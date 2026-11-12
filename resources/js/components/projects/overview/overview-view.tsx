import { useTranslation } from 'react-i18next';
import { ApplicationSection } from '@/components/reports/application-section';
import type { FindingFix } from '@/components/reports/fix-actions';
import {
    DependenciesCard,
    InfrastructureCard,
} from '@/components/reports/health-cards';
import { shortRelease } from '@/components/reports/release-label';
import {
    RUNTIMES,
    runtimeLabel,
    supportStatus,
} from '@/components/reports/runtime-support';
import {
    SEVERITIES,
    severitySummary,
} from '@/components/reports/severity-badge';
import { StatCard, trendOf } from '@/components/stat-card';
import { cn } from '@/lib/utils';
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
 * Problems first: a row of stat cards (shadcn `dashboard-01`, as the dashboard) for findings,
 * checks, runtimes and, when the project pushes them, exceptions and requests; then what needs
 * attention, worst first, with its fix; then the runtimes and every check.
 */
export function OverviewView({
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
                total={findingGroups.length + openExceptions.length}
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
