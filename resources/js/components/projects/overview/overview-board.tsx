import { useTranslation } from 'react-i18next';
import { KpiTile, kpiDeltaOf } from '@/components/charts/kpi-tile';
import {
    historySparkline,
    KpiRatio,
    OpenExceptionsKpi,
} from '@/components/dashboard/kpi-history';
import { ReportCompositions } from '@/components/dashboard/report-compositions';
import {
    RUNTIMES,
    runtimeLabel,
    supportStatus,
} from '@/components/reports/runtime-support';
import { findingsCount, relativeTime } from '@/lib/report-format';
import { cn } from '@/lib/utils';
import type { HealthReportSummary } from '@/types';
import type { RuntimeOverview } from '@/types/dashboard';
import type { DashboardHistory } from '@/types/dashboard';

/** The project Board, with its current readings above their two compositions. */
export function OverviewBoard({
    latest,
    history,
    hasExceptions,
    runtime,
}: {
    latest: HealthReportSummary;
    history: DashboardHistory;
    hasExceptions: boolean;
    runtime: RuntimeOverview | null;
}) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
    const counts = latest.counts;
    const findings = findingsCount(counts);
    const checks = latest.checks;
    const checksTotal =
        checks.pass + checks.warn + checks.fail + checks.skipped;
    const runtimes = RUNTIMES.flatMap((name) => {
        const version = latest.runtime?.[name];

        return version ? [{ name, version }] : [];
    });
    const unsupported = runtimes.find(
        ({ version }) =>
            supportStatus(version.security_support_ends_on) !== 'supported',
    );
    const supported = runtimes.filter(
        ({ version }) =>
            supportStatus(version.security_support_ends_on) === 'supported',
    ).length;
    const requestCount = runtime?.requests.count ?? 0;
    const errors = runtime?.requests.errors ?? 0;
    const errorRate = requestCount > 0 ? (errors / requestCount) * 100 : 0;
    const averageMs = runtime?.requests.averageMs;
    const compareSnapshot = t('charts.previousSnapshot', { count: 30 });
    const compareRange = t('charts.previousRange');
    const findingsDelta = kpiDeltaOf(
        findings,
        history.findings?.previous ?? null,
        false,
    );
    const checksDelta = kpiDeltaOf(
        checks.pass,
        history.checks?.previous ?? null,
        true,
    );
    const exceptions = history.exceptions;
    const requestDelta =
        requestCount > 0
            ? kpiDeltaOf(requestCount, history.requests?.previous ?? null, null)
            : null;

    return (
        <>
            <section
                aria-label={t('charts.keyMetrics')}
                className={cn(
                    // An odd last tile spans the two-column row (app-ui docs/charts.md).
                    'grid grid-cols-1 gap-4 sm:grid-cols-2 sm:*:last:odd:col-span-2 xl:*:last:odd:col-span-1',
                    hasExceptions && runtime !== null
                        ? 'xl:grid-cols-5'
                        : hasExceptions || runtime !== null
                          ? 'xl:grid-cols-4'
                          : 'xl:grid-cols-3',
                )}
            >
                <KpiTile
                    label={t('report.overview.findings')}
                    value={findings.toLocaleString(locale)}
                    delta={findingsDelta}
                    secondary={
                        findingsDelta
                            ? compareSnapshot
                            : t('report.overview.lastReport', {
                                  time: relativeTime(
                                      latest.received_at,
                                      locale,
                                  ),
                              })
                    }
                    chart={historySparkline(history.findings)}
                />
                <KpiTile
                    label={t('report.overview.checks')}
                    value={
                        <KpiRatio
                            value={checks.pass}
                            total={checksTotal}
                            locale={locale}
                        />
                    }
                    delta={checksDelta}
                    secondary={checksDelta ? compareSnapshot : undefined}
                    chart={historySparkline(history.checks)}
                />
                <KpiTile
                    label={t('report.overview.runtimes')}
                    value={
                        runtimes.length === 0 ? (
                            '—'
                        ) : (
                            <KpiRatio
                                value={supported}
                                total={runtimes.length}
                                locale={locale}
                            />
                        )
                    }
                    secondary={
                        runtimes.length === 0
                            ? t('report.overview.runtimesUnavailable')
                            : unsupported
                              ? t('report.overview.runtimeStatus', {
                                    runtime: runtimeLabel(
                                        unsupported.name,
                                        unsupported.version,
                                        t,
                                    ),
                                    status: t(
                                        `report.support.${supportStatus(unsupported.version.security_support_ends_on)}`,
                                    ).toLowerCase(),
                                })
                              : runtimes
                                    .map(({ name, version }) =>
                                        runtimeLabel(name, version, t),
                                    )
                                    .join(', ')
                    }
                />
                {hasExceptions && exceptions ? (
                    <OpenExceptionsKpi history={exceptions} />
                ) : null}
                {runtime ? (
                    <KpiTile
                        label={
                            runtime.range === 'custom'
                                ? t('runtime.requests')
                                : t('report.overview.requests', {
                                      count: runtime.hours,
                                  })
                        }
                        value={requestCount.toLocaleString(locale)}
                        delta={requestDelta}
                        secondary={[
                            t('dashboard.telemetry.errors', {
                                count: errors,
                                rate: errorRate.toLocaleString(locale, {
                                    maximumFractionDigits:
                                        errorRate < 1 ? 2 : 1,
                                }),
                            }),
                            averageMs != null
                                ? t('report.overview.averageMs', {
                                      ms: averageMs.toLocaleString(locale),
                                  })
                                : null,
                            requestDelta ? compareRange : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        chart={historySparkline(history.requests)}
                    />
                ) : null}
            </section>
            <ReportCompositions findings={counts} checks={checks} />
        </>
    );
}
