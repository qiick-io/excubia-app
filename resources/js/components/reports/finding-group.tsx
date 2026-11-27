import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import fleetRoutes from '@/routes/fleet';

/** The check whose repeated findings link to the upgrade plan in Fleet. */
const UPGRADE_BLOCKER_CHECK = 'laravel-next-blocker';

/**
 * A row of findings that share a check and a message (#291), as "Needs attention" on the
 * project overview and "Top issues" on the dashboard show it: a title with the count
 * ("6 packages block Laravel 14"), the affected items behind a collapsible list and, for
 * next-major blockers, the link to the upgrade plan in Fleet. Grouping is display only:
 * each finding keeps its own state on the Findings tab.
 */
export function FindingGroupSummary({
    checkId,
    checkLabel,
    message,
    targetMajor,
    items,
}: {
    checkId: string;
    checkLabel: string;
    message: string;
    targetMajor: number | null;
    items: { key: string; label: ReactNode }[];
}) {
    const { t } = useTranslation();
    const isUpgradeBlocker =
        checkId === UPGRADE_BLOCKER_CHECK && targetMajor !== null;

    return (
        <Collapsible>
            <div className="font-medium">
                {isUpgradeBlocker
                    ? t('report.findingGroup.blockers', {
                          count: items.length,
                          major: targetMajor,
                      })
                    : t('report.findingGroup.title', { count: items.length })}
            </div>
            <div className="text-xs text-muted-foreground">{checkLabel}</div>
            {isUpgradeBlocker ? null : (
                <div className="mt-1 text-sm">{message}</div>
            )}
            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                <CollapsibleTrigger className="group inline-flex items-center gap-1 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden">
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 transition-transform group-data-[state=open]:rotate-90"
                    />
                    {t('report.findingGroup.show', { count: items.length })}
                </CollapsibleTrigger>
                {checkId === UPGRADE_BLOCKER_CHECK ? (
                    <Button variant="link" size="sm" className="px-0" asChild>
                        <Link href={fleetRoutes.show.url()}>
                            {t('report.findingGroup.upgradePlan')}
                        </Link>
                    </Button>
                ) : null}
            </div>
            <CollapsibleContent>
                <ul className="mt-2 space-y-1 text-sm">
                    {items.map((item) => (
                        <li key={item.key}>{item.label}</li>
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}
