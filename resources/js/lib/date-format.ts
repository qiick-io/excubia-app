import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

/** An ISO string, a timestamp in milliseconds or a Date; empty shows a dash. */
export type DateValue = string | number | Date | null | undefined;

/**
 * Formats an instant in the app's language and the user's timezone (#428), never the browser's:
 * every date in Excubia reads the same way, "26 Nov 2026, 20:29" in English or Italian alike.
 * `options.timeZone` wins, for calendar days and UTC buckets.
 */
export function formatInstant(
    value: DateValue,
    locale: string,
    timeZone: string | undefined,
    options: Intl.DateTimeFormatOptions,
): string {
    const date =
        value === null || value === undefined || value === ''
            ? null
            : new Date(value);

    // An empty or broken value shows a dash instead of 1970 or a RangeError.
    if (date === null || Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, { timeZone, ...options }).format(
        date,
    );
}

/**
 * A calendar day (`YYYY-MM-DD`) as written: read and shown in UTC, so no timezone moves it to
 * the day before or after. Something that is not a real day is shown as it came.
 */
function formatCalendarDay(day: string, locale: string): string {
    const midnight = new Date(`${day}T00:00:00Z`);

    // 2026-02-31 would roll over to March.
    if (
        Number.isNaN(midnight.getTime()) ||
        midnight.toISOString().slice(0, 10) !== day
    ) {
        return day;
    }

    return formatInstant(midnight, locale, 'UTC', { dateStyle: 'medium' });
}

/** The zone if this browser knows it, else undefined (the browser's own). */
function knownTimeZone(timeZone: string | undefined): string | undefined {
    if (!timeZone) {
        return undefined;
    }

    try {
        new Intl.DateTimeFormat(undefined, { timeZone });

        return timeZone;
    } catch {
        return undefined;
    }
}

/**
 * The app's date formatters, bound to the current language and the signed-in user's timezone
 * (Settings → Profile; the browser's when unknown or not supported by this browser).
 */
export function useDateFormat() {
    const { i18n } = useTranslation();
    const { auth } = usePage<{
        auth?: { user?: { timezone?: string } | null };
    }>().props;
    const locale = i18n.language;
    const timeZone = knownTimeZone(auth?.user?.timezone);

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
        /** A `YYYY-MM-DD` calendar day: "26 Nov 2026"; empty shows a dash. */
        day: (day: string | null | undefined): string =>
            day ? formatCalendarDay(day, locale) : '—',
        /** Any other shape (chart axes), still in the app's language and the user's timezone. */
        format: (
            value: DateValue,
            options: Intl.DateTimeFormatOptions,
        ): string => formatInstant(value, locale, timeZone, options),
    };
}
