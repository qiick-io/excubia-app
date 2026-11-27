import { Sparkline } from '@/components/charts/sparkline';
import { KpiTile, kpiDeltaOf } from '@/components/charts/kpi-tile';
import { useTranslation } from 'react-i18next';
import { useNumberFormat } from '@/hooks/use-number-format';
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
export function KpiRatio({ value, total }: { value: number; total: number }) {
    const formatNumber = useNumberFormat();

    return (
        <>
            {formatNumber(value)}
            <span className="text-lg text-muted-foreground">
                /{formatNumber(total)}
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
    const { t } = useTranslation();
    const formatNumber = useNumberFormat();
    const delta = kpiDeltaOf(
        history.current,
        history.previous ?? null,
        false,
        formatNumber,
    );

    return (
        <KpiTile
            className={className}
            label={t('dashboard.telemetry.openExceptions')}
            value={formatNumber(history.current)}
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
