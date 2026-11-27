import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

/** Formats numeric telemetry with the selected UI language. */
export function useNumberFormat(): (value: number) => string {
    const { i18n } = useTranslation();
    const locale = i18n.resolvedLanguage ?? i18n.language;

    return useMemo(() => {
        const formatter = new Intl.NumberFormat(locale);

        return (value: number) => formatter.format(value);
    }, [locale]);
}
