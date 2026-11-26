import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/** An ISO string, a timestamp in milliseconds or a Date. */
export type DateValue = string | number | Date;

/**
 * Formats an instant in the app's language and the user's timezone (#428), never the browser's:
 * every date in Excubia reads the same way, "26 Nov 2026, 20:29" in English or Italian alike.
 */
export function formatInstant(
    value: DateValue,
    locale: string,
    timeZone: string | undefined,
    options: Intl.DateTimeFormatOptions,
): string {
    const date = new Date(value);

    // An empty or broken value shows a dash instead of throwing a RangeError.
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(
        date,
    );
}

/**
 * A calendar day (`YYYY-MM-DD`) as written: read and shown in UTC, so no timezone moves it to
 * the day before or after.
 */
function formatCalendarDay(day: string, locale: string): string {
    return formatInstant(`${day}T00:00:00Z`, locale, 'UTC', {
        dateStyle: 'medium',
    });
}

/**
 * The app's date formatters, bound to the current language and the signed-in user's timezone
 * (Settings → Profile; the browser's when unknown).
 */
export function useDateFormat() {
    const { i18n } = useTranslation();
    const { auth } = usePage<{
        auth?: { user?: { timezone?: string } | null };
    }>().props;
    const locale = i18n.language;
    const timeZone = auth?.user?.timezone || undefined;

    return {
        locale,
        timeZone,
        /** Date and time: "26 Nov 2026, 20:29". */
        dateTime: (value: DateValue): string =>
            formatInstant(value, locale, timeZone, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }),
        /** The date only: "26 Nov 2026". */
        date: (value: DateValue): string =>
            formatInstant(value, locale, timeZone, { dateStyle: 'medium' }),
        /** The time only: "20:29". */
        time: (value: DateValue): string =>
            formatInstant(value, locale, timeZone, { timeStyle: 'short' }),
        /** A `YYYY-MM-DD` calendar day: "26 Nov 2026". */
        day: (day: string): string => formatCalendarDay(day, locale),
        /** Any other shape (chart axes), still in the app's language and the user's timezone. */
        format: (
            value: DateValue,
            options: Intl.DateTimeFormatOptions,
        ): string => formatInstant(value, locale, timeZone, options),
    };
}
