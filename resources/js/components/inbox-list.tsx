import { Link } from '@inertiajs/react';
import { ChevronRight, ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Inbox list (optional pattern `inbox-list`, docs/optional/inbox-list.md).
 *
 * A work queue (exceptions, alerts, findings, tickets) is triaged, not
 * browsed: people go through it item by item and act on each. On top of the
 * core list page (toolbar, selection bar, DataTable) this adds:
 * - `InboxSavedViews`: preset filter sets ("Unresolved", "Assigned to me")
 *   as URL links above the toolbar, with counts;
 * - `InboxDetailSheet`: the item opened beside the list (`?item=` in the
 *   URL), so the list keeps its scroll, filters and selection; its layout is
 *   fixed: summary and actions, what to do next, then collapsed details.
 */

export type InboxSavedView = {
    id: string;
    /** Already translated. */
    label: string;
    href: string;
    count?: number;
};

/**
 * Saved views as one segmented row of links, above the filter toolbar.
 * Each is a URL with its filters, so it can be shared and bookmarked.
 */
export function InboxSavedViews({
    views,
    activeViewId,
}: {
    views: InboxSavedView[];
    /** The view whose filters match the URL; none when the person changed them. */
    activeViewId?: string;
}) {
    const { t } = useTranslation();

    return (
        <nav aria-label={t('inbox.savedViews')}>
            <ButtonGroup>
                {views.map((view) => {
                    const isActive = view.id === activeViewId;

                    return (
                        <Button
                            key={view.id}
                            asChild
                            variant={isActive ? 'secondary' : 'outline'}
                        >
                            <Link
                                href={view.href}
                                preserveScroll
                                aria-current={isActive ? 'page' : undefined}
                            >
                                {view.label}
                                {view.count !== undefined ? (
                                    <Badge
                                        variant="outline"
                                        className="h-5 px-1.5 tabular-nums"
                                    >
                                        {view.count}
                                    </Badge>
                                ) : null}
                            </Link>
                        </Button>
                    );
                })}
            </ButtonGroup>
        </nav>
    );
}

export type InboxDetailSection = {
    id: string;
    /** Already translated. */
    title: string;
    content: ReactNode;
    /** Sections are collapsed unless the app opens one (e.g. the trace on errors). */
    defaultOpen?: boolean;
};

type InboxDetailSheetProps = {
    open: boolean;
    /** Closing removes `?item=` from the URL; the app does that. */
    onOpenChange: (open: boolean) => void;
    title: string;
    /** One line under the title: type, first and last seen, occurrences. */
    description?: string;
    /** Severity and status badges, before the title's metadata. */
    status?: ReactNode;
    /** The item's actions (assign, resolve, mute), right under the summary. */
    actions?: ReactNode;
    /** "What to do": the ready command, prompt or next step. */
    nextStep?: ReactNode;
    sections?: InboxDetailSection[];
    fullPageHref: string;
    /** While the item's data loads (a partial reload). */
    isLoading?: boolean;
};

/**
 * One inbox item beside the list, in a right `Sheet`.
 */
export function InboxDetailSheet({
    open,
    onOpenChange,
    title,
    description,
    status,
    actions,
    nextStep,
    sections = [],
    fullPageHref,
    isLoading = false,
}: InboxDetailSheetProps) {
    const { t } = useTranslation();

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto sm:max-w-xl"
                aria-busy={isLoading || undefined}
            >
                <SheetHeader className="gap-2 border-b pr-12">
                    {status ? (
                        <div className="flex flex-wrap items-center gap-2">
                            {status}
                        </div>
                    ) : null}
                    <SheetTitle className="text-base">
                        {isLoading ? <Skeleton className="h-5 w-2/3" /> : title}
                    </SheetTitle>
                    {description ? (
                        <SheetDescription>{description}</SheetDescription>
                    ) : (
                        <SheetDescription className="sr-only">
                            {t('inbox.detailDescription')}
                        </SheetDescription>
                    )}
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        {actions}
                        <Button asChild variant="ghost" className="ml-auto">
                            <Link href={fullPageHref}>
                                {t('inbox.openFullPage')}
                                <ExternalLink
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </Link>
                        </Button>
                    </div>
                </SheetHeader>
                {nextStep ? (
                    <section
                        aria-labelledby="inbox-next-step"
                        className="grid gap-2 border-b p-4"
                    >
                        <h3
                            id="inbox-next-step"
                            className="text-sm font-medium"
                        >
                            {t('inbox.nextStep')}
                        </h3>
                        {nextStep}
                    </section>
                ) : null}
                {sections.map((section) => (
                    <Collapsible
                        key={section.id}
                        defaultOpen={section.defaultOpen}
                        className="border-b"
                    >
                        <CollapsibleTrigger className="group flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden">
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-90"
                            />
                            {section.title}
                        </CollapsibleTrigger>
                        <CollapsibleContent className="px-4 pb-4">
                            {section.content}
                        </CollapsibleContent>
                    </Collapsible>
                ))}
            </SheetContent>
        </Sheet>
    );
}
