import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

export type FormatNumber = (
    value: number,
    options?: Intl.NumberFormatOptions,
) => string;

/** Formats numeric telemetry with the selected UI language. */
export function useNumberFormat(): FormatNumber {
    const { i18n } = useTranslation();
    const locale = i18n.resolvedLanguage ?? i18n.language;

    return useMemo(() => {
        const formatter = new Intl.NumberFormat(locale);

        return (value: number, options?: Intl.NumberFormatOptions) =>
            options
                ? value.toLocaleString(locale, options)
                : formatter.format(value);
    }, [locale]);
}
