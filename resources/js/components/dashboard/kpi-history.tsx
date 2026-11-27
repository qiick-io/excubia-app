import { Sparkline } from '@/components/charts/sparkline';
import { KpiTile, kpiDeltaOf } from '@/components/charts/kpi-tile';
import { useTranslation } from 'react-i18next';
import type { KpiHistory } from '@/types/dashboard';

/** Renders the latest continuous trend. Missing days stay unknown, including missing latency. */
export function historySparkline(history: KpiHistory | undefined) {
    const days = history?.values ?? [];
    const values = days
        .slice(days.findLastIndex((value) => value === null) + 1)
        .filter((value) => value !== null);

    return values.length >= 3 && values.some((value) => value !== values[0]) ? (
        <Sparkline values={values} />
    ) : null;
}

/** Keeps the denominator available when the eye is scanning for the main count. */
export function KpiRatio({
    value,
    total,
    locale,
}: {
    value: number;
    total: number;
    locale: string;
}) {
    return (
        <>
            {value.toLocaleString(locale)}
            <span className="text-lg text-muted-foreground">
                /{total.toLocaleString(locale)}
            </span>
        </>
    );
}

/** The size of the project's or fleet's open exception inbox, independent of list filters. */
export function OpenExceptionsKpi({
    history,
    className,
    secondary,
    comparisonDays = 30,
}: {
    history: KpiHistory & { current: number };
    className?: string;
    secondary?: string;
    comparisonDays?: number;
}) {
    const { t, i18n } = useTranslation();
    const delta = kpiDeltaOf(history.current, history.previous ?? null, false);

    return (
        <KpiTile
            className={className}
            label={t('dashboard.telemetry.openExceptions')}
            value={history.current.toLocaleString(i18n.language)}
            delta={delta}
            secondary={[
                secondary,
                delta
                    ? t('charts.previousSnapshot', { count: comparisonDays })
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            chart={historySparkline(history)}
        />
    );
}
