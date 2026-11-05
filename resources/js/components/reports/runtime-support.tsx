import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import type { RuntimeName, RuntimeVersion } from '@/types';

export const RUNTIMES: RuntimeName[] = ['php', 'laravel', 'node'];

/** Days before the end of security support when a runtime counts as "ending soon". */
const ENDING_SOON_DAYS = 180;

export type SupportStatus = 'supported' | 'ending' | 'ended' | 'unknown';

/**
 * Where a runtime stands against its security support end date (`YYYY-MM-DD`, UTC).
 */
export function supportStatus(
    endsOn: string | null | undefined,
): SupportStatus {
    if (!endsOn) {
        return 'unknown';
    }

    const days = (Date.parse(`${endsOn}T00:00:00Z`) - Date.now()) / 86_400_000;

    if (Number.isNaN(days)) {
        return 'unknown';
    }

    if (days < 0) {
        return 'ended';
    }

    return days <= ENDING_SOON_DAYS ? 'ending' : 'supported';
}

const STATUS_VARIANTS = {
    supported: 'success',
    ending: 'warning',
    ended: 'destructive',
    unknown: 'muted',
} as const;

/**
 * Support status of a runtime as a status badge; the text names the status, so colour is
 * never the only signal.
 */
export function RuntimeSupportBadge({
    runtime,
}: {
    runtime: RuntimeVersion | null;
}) {
    const { t } = useTranslation();
    const status = supportStatus(runtime?.security_support_ends_on);

    return (
        <Badge variant={STATUS_VARIANTS[status]}>
            {t(`report.support.${status}`)}
        </Badge>
    );
}

/**
 * "PHP 8.4" style label: the runtime name and its locked version, or its cycle.
 */
export function runtimeLabel(
    name: RuntimeName,
    runtime: RuntimeVersion | null,
    t: (key: string) => string,
): string {
    return `${t(`report.runtime.${name}`)} ${runtime?.version ?? runtime?.cycle ?? '—'}`;
}
