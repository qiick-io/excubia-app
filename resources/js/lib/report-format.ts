import type { ReportFinding } from '@/types';

/**
 * "5 minutes ago" in the user's language, from an ISO date.
 */
export function relativeTime(iso: string, locale: string): string {
    const seconds = (Date.parse(iso) - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'minute');
}

/** Same key as ReportFindingsService::identity() on the server. */
export function findingKey(row: ReportFinding): string {
    return `${row.checkId}|${row.subject}|${row.advisoryId ?? ''}`;
}
