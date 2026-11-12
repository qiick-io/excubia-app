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

/**
 * Findings that share a check id and a message, as one display row (#291): "6 packages block
 * Laravel 14" instead of six rows. Groups keep the order of their first (worst) finding, so a
 * list sorted worst first stays sorted; each finding keeps its own key and state.
 */
export function groupFindings<Finding extends ReportFinding>(
    findings: Finding[],
): Finding[][] {
    const groupsByCheckAndMessage = new Map<string, Finding[]>();

    for (const finding of findings) {
        const groupKey = `${finding.checkId}|${finding.message}`;
        const group = groupsByCheckAndMessage.get(groupKey);

        if (group === undefined) {
            groupsByCheckAndMessage.set(groupKey, [finding]);
        } else {
            group.push(finding);
        }
    }

    return [...groupsByCheckAndMessage.values()];
}
