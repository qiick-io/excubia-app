import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CircleCheck,
    OctagonAlert,
    Search,
    Star,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { createContext, useContext, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarInput,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

/**
 * Sidebar extensions (optional pattern `sidebar-entities`,
 * docs/optional/sidebar-entities.md).
 *
 * When the main entity of an app (projects, workspaces, clients) has more
 * records than the nav can list, people still come back to a few of them.
 * This puts those few under their nav item, without opening the index:
 * - **Pinned**: the user's own picks, in a stable order;
 * - **Needs attention**: records with open problems, worst first, hidden
 *   when nothing needs attention.
 * The parent nav item keeps linking to the index, and "View all" ends the
 * list. In the icon-only sidebar the same blocks open from the parent item.
 *
 * `SidebarFilterProvider` / `SidebarFilterInput` add a filter field at the
 * top of the nav; entity lists and the app's nav items read it with
 * `useSidebarFilter`.
 */

export type SidebarEntityStatus = 'good' | 'warning' | 'critical';

export type SidebarEntity = {
    id: string;
    /** Already translated or a record name. */
    label: string;
    href: string;
    status?: SidebarEntityStatus;
    isActive?: boolean;
};

const STATUS_ICONS: Record<SidebarEntityStatus, LucideIcon> = {
    good: CircleCheck,
    warning: TriangleAlert,
    critical: OctagonAlert,
};

const STATUS_CLASSES: Record<SidebarEntityStatus, string> = {
    good: 'text-success',
    warning: 'text-warning-text',
    critical: 'text-destructive',
};

/** A status icon (shape and colour) with its label for screen readers. */
function EntityStatus({ status }: { status?: SidebarEntityStatus }) {
    const { t } = useTranslation();

    if (!status) {
        return null;
    }

    const Icon = STATUS_ICONS[status];

    // Wrapped, so the sidebar button's `[&>svg]` colour does not override the
    // status colour.
    return (
        <span className="flex shrink-0">
            <Icon
                aria-hidden="true"
                className={cn('size-3.5', STATUS_CLASSES[status])}
            />
            <span className="sr-only">
                {t(`sidebarEntities.status.${status}`)}
            </span>
        </span>
    );
}

type SidebarFilterContextValue = {
    query: string;
    setQuery: (query: string) => void;
};

const SidebarFilterContext = createContext<SidebarFilterContextValue>({
    query: '',
    setQuery: () => undefined,
});

/** Holds the sidebar filter query; wrap the sidebar content once. */
export function SidebarFilterProvider({ children }: { children: ReactNode }) {
    const [query, setQuery] = useState('');
    const contextValue = useMemo(() => ({ query, setQuery }), [query]);

    return (
        <SidebarFilterContext.Provider value={contextValue}>
            {children}
        </SidebarFilterContext.Provider>
    );
}

/**
 * Whether a label matches the sidebar filter (always true without a query).
 * Nav items call it to hide themselves while the person filters.
 */
export function useSidebarFilter(label: string): boolean {
    const { query } = useContext(SidebarFilterContext);
    const trimmedQuery = query.trim().toLowerCase();

    return trimmedQuery === '' || label.toLowerCase().includes(trimmedQuery);
}

/**
 * The filter field, as the first group of the sidebar content. Hidden in the
 * icon-only sidebar, where ⌘K (the command palette) takes over.
 */
export function SidebarFilterInput() {
    const { t } = useTranslation();
    const { query, setQuery } = useContext(SidebarFilterContext);

    return (
        <SidebarGroup className="py-0 group-data-[collapsible=icon]:hidden">
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <SidebarInput
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setQuery('');
                        }
                    }}
                    placeholder={t('sidebarEntities.filterPlaceholder')}
                    aria-label={t('sidebarEntities.filter')}
                    className="pl-8"
                />
            </div>
        </SidebarGroup>
    );
}

type SidebarEntityListProps = {
    pinned: SidebarEntity[];
    /** Worst first; the app caps it (about 5). */
    needsAttention?: SidebarEntity[];
    viewAllHref: string;
};

/**
 * The blocks under a nav item, in the expanded sidebar. Render it inside the
 * parent `SidebarMenuItem`, after its `SidebarMenuButton`.
 */
export function SidebarEntityList({
    pinned,
    needsAttention = [],
    viewAllHref,
}: SidebarEntityListProps) {
    const { t } = useTranslation();
    const { query } = useContext(SidebarFilterContext);
    const trimmedQuery = query.trim().toLowerCase();
    const matches = (entity: SidebarEntity) =>
        trimmedQuery === '' ||
        entity.label.toLowerCase().includes(trimmedQuery);
    const visiblePinned = pinned.filter(matches);
    const pinnedIds = new Set(pinned.map((entity) => entity.id));
    const visibleAttention = needsAttention
        .filter((entity) => !pinnedIds.has(entity.id))
        .filter(matches);

    return (
        <SidebarMenuSub>
            <EntityBlock
                heading={t('sidebarEntities.pinned')}
                entities={visiblePinned}
                emptyText={
                    trimmedQuery === '' ? t('sidebarEntities.pinHint') : null
                }
            />
            {visibleAttention.length > 0 ? (
                <EntityBlock
                    heading={t('sidebarEntities.needsAttention')}
                    entities={visibleAttention}
                />
            ) : null}
            <SidebarMenuSubItem>
                <SidebarMenuSubButton asChild size="sm">
                    <Link href={viewAllHref}>
                        <ArrowRight aria-hidden="true" />
                        <span>{t('sidebarEntities.viewAll')}</span>
                    </Link>
                </SidebarMenuSubButton>
            </SidebarMenuSubItem>
        </SidebarMenuSub>
    );
}

function EntityBlock({
    heading,
    entities,
    emptyText = null,
}: {
    heading: string;
    entities: SidebarEntity[];
    emptyText?: string | null;
}) {
    if (entities.length === 0 && emptyText === null) {
        return null;
    }

    return (
        <li className="grid gap-0.5">
            <p className="px-2 pt-1 text-xs font-medium text-sidebar-foreground/70">
                {heading}
            </p>
            {entities.length === 0 ? (
                <p className="px-2 pb-1 text-xs text-sidebar-foreground/70">
                    {emptyText}
                </p>
            ) : (
                <ul className="grid gap-0.5" aria-label={heading}>
                    {entities.map((entity) => (
                        <SidebarMenuSubItem key={entity.id}>
                            <SidebarMenuSubButton
                                asChild
                                isActive={entity.isActive}
                            >
                                <Link
                                    href={entity.href}
                                    aria-current={
                                        entity.isActive ? 'page' : undefined
                                    }
                                >
                                    <EntityStatus status={entity.status} />
                                    <span>{entity.label}</span>
                                </Link>
                            </SidebarMenuSubButton>
                        </SidebarMenuSubItem>
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * The icon-only sidebar: wraps the parent nav button so it opens the same
 * blocks as a menu beside the sidebar. Expanded, it renders the button as is.
 */
export function SidebarEntityFlyout({
    label,
    pinned,
    needsAttention = [],
    viewAllHref,
    children,
}: SidebarEntityListProps & {
    /** The parent item's label, as the menu title. */
    label: string;
    /** The parent `SidebarMenuButton`. */
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const { state, isMobile } = useSidebar();

    if (state === 'expanded' || isMobile) {
        return children;
    }

    const pinnedIds = new Set(pinned.map((entity) => entity.id));
    const attention = needsAttention.filter(
        (entity) => !pinnedIds.has(entity.id),
    );
    const menuBlocks = [
        { heading: t('sidebarEntities.pinned'), entities: pinned },
        { heading: t('sidebarEntities.needsAttention'), entities: attention },
    ].filter((block) => block.entities.length > 0);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{children}</DropdownMenuTrigger>
            <DropdownMenuContent side="right" align="start" className="w-60">
                <DropdownMenuLabel>{label}</DropdownMenuLabel>
                {menuBlocks.map((block) => (
                    <DropdownMenuGroup key={block.heading}>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                            {block.heading}
                        </DropdownMenuLabel>
                        {block.entities.map((entity) => (
                            <DropdownMenuItem key={entity.id} asChild>
                                <Link
                                    href={entity.href}
                                    aria-current={
                                        entity.isActive ? 'page' : undefined
                                    }
                                >
                                    <EntityStatus status={entity.status} />
                                    <span className="truncate">
                                        {entity.label}
                                    </span>
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={viewAllHref}>
                        <ArrowRight aria-hidden="true" />
                        {t('sidebarEntities.viewAll')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The star that pins or unpins a record, for list rows and record headers.
 * The app saves the pin (one request) and reloads the shared prop.
 */
export function PinToggleButton({
    isPinned,
    onToggle,
    entityLabel,
    disabled,
}: {
    isPinned: boolean;
    onToggle: () => void;
    /** The record's name, for the accessible label ("Pin Billing API"). */
    entityLabel: string;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const label = isPinned
        ? t('sidebarEntities.unpin', { name: entityLabel })
        : t('sidebarEntities.pin', { name: entityLabel });

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={label}
            aria-pressed={isPinned}
            title={label}
            disabled={disabled}
            onClick={onToggle}
        >
            <Star
                aria-hidden="true"
                className={cn(isPinned && 'fill-current text-primary')}
            />
        </Button>
    );
}
