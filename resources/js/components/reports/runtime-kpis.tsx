import { useTranslation } from 'react-i18next';
import { KpiTile, kpiDeltaOf } from '@/components/charts/kpi-tile';
import { historySparkline } from '@/components/dashboard/kpi-history';
import type { RuntimeSummary } from '@/types';
import type { DashboardHistory } from '@/types/dashboard';

/** Runtime readings for the selected window with daily traffic and latency history. */
export function RuntimeKpis({
    runtime,
    history,
    rangeLabel,
    formatCount,
    formatMs,
}: {
    runtime: RuntimeSummary;
    history: DashboardHistory;
    rangeLabel: string;
    formatCount: (value: number) => string;
    formatMs: (value: number | null) => string;
}) {
    const { t, i18n } = useTranslation();
    const requests = runtime.requests;
    const errorRate =
        requests.count > 0 ? (requests.errors / requests.count) * 100 : 0;
    const requestsDelta =
        requests.count > 0
            ? kpiDeltaOf(
                  requests.count,
                  history.requests?.previous ?? null,
                  null,
              )
            : null;
    const latencyDelta =
        requests.averageMs === null
            ? null
            : kpiDeltaOf(
                  requests.averageMs,
                  history.latency?.previous ?? null,
                  false,
              );

    return (
        <section
            aria-label={t('charts.keyMetrics')}
            className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <KpiTile
                label={t('runtime.requests')}
                value={formatCount(requests.count)}
                delta={requestsDelta}
                secondary={[
                    requestsDelta ? t('charts.previousRange') : rangeLabel,
                    runtime.isSampled ? t('runtime.sampled') : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                chart={historySparkline(history.requests)}
            />
            <KpiTile
                label={t('runtime.errors')}
                value={formatCount(requests.errors)}
                secondary={t('runtime.errorRate', {
                    rate: errorRate.toLocaleString(i18n.language, {
                        maximumFractionDigits: errorRate < 1 ? 2 : 1,
                    }),
                })}
            />
            <KpiTile
                label={t('runtime.averageResponse')}
                value={
                    requests.averageMs === null
                        ? formatMs(null)
                        : `${requests.averageMs.toLocaleString(i18n.language)} ms`
                }
                delta={latencyDelta}
                secondary={[
                    requests.p50 === null
                        ? null
                        : `p50 ${formatMs(requests.p50)} · p95 ${formatMs(requests.p95)} · p99 ${formatMs(requests.p99)}`,
                    latencyDelta
                        ? t('charts.previousRange')
                        : t('runtime.allRoutes'),
                ]
                    .filter(Boolean)
                    .join(' · ')}
                chart={historySparkline(history.latency)}
            />
            <KpiTile
                label={t('runtime.slowestResponse')}
                value={formatMs(requests.maxMs)}
                secondary={t('runtime.allRoutes')}
            />
        </section>
    );
}
