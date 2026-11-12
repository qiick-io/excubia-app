import { Link } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import {
    ArrowRight,
    CircleCheck,
    OctagonAlert,
    Search,
    Star,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
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
    SidebarMenuAction,
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
 *
 * With `onTogglePin`, every row gets a pin action: a star at the right of
 * the row (shown on hover and focus, always on touch screens), the same
 * action in the row's right-click menu, and a star next to each record in
 * the icon-only flyout. Without it, the lists only link.
 */

export type SidebarEntityStatus = 'good' | 'warning' | 'critical';

export type SidebarEntity = {
    id: string;
    /** Already translated or a record name. */
    label: string;
    href: string;
    status?: SidebarEntityStatus;
    /** A small decorative visual before the label, such as the record's avatar. */
    leading?: ReactNode;
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

/** "Pin Billing API" or "Unpin Billing API": the label of every pin action. */
function pinActionLabel(
    translate: TFunction,
    isPinned: boolean,
    entityLabel: string,
): string {
    return isPinned
        ? translate('sidebarEntities.unpin', { name: entityLabel })
        : translate('sidebarEntities.pin', { name: entityLabel });
}

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
    /**
     * Pins or unpins a record from its row. The app saves the change (one
     * request) and reloads the shared prop, like `PinToggleButton`. Leave it
     * out for lists without a pin action.
     */
    onTogglePin?: (entity: SidebarEntity) => void;
};

/** A toggle waiting for the app's reload, to put focus back on its row. */
type PendingPinFocus = { entityId: string; wasPinned: boolean };

/**
 * The blocks under a nav item, in the expanded sidebar. Render it inside the
 * parent `SidebarMenuItem`, after its `SidebarMenuButton`.
 */
export function SidebarEntityList({
    pinned,
    needsAttention = [],
    viewAllHref,
    onTogglePin,
    shouldShowPinHint = true,
}: SidebarEntityListProps & {
    /**
     * The "pin a project with the star" text under an empty Pinned block.
     * Turn it off when the app explains pinning once on its list page.
     */
    shouldShowPinHint?: boolean;
}) {
    const { t } = useTranslation();
    const { query } = useContext(SidebarFilterContext);
    const listRef = useRef<HTMLUListElement>(null);
    const pendingPinFocusRef = useRef<PendingPinFocus | null>(null);

    // A toggled row moves between Pinned and Needs attention once the app
    // reloads the prop, so React mounts it again and the focused star is
    // gone. Put focus back on the row's star in its new block, or on "View
    // all" when the record left both blocks. Only when focus was lost:
    // focus the person moved elsewhere in the meantime stays where it is.
    useEffect(() => {
        const pendingPinFocus = pendingPinFocusRef.current;
        const isPinnedNow = pinned.some(
            (entity) => entity.id === pendingPinFocus?.entityId,
        );

        if (
            pendingPinFocus === null ||
            isPinnedNow === pendingPinFocus.wasPinned
        ) {
            return;
        }

        pendingPinFocusRef.current = null;
        const hasLostFocus =
            document.activeElement === null ||
            document.activeElement === document.body;

        if (!hasLostFocus || listRef.current === null) {
            return;
        }

        const movedStar = Array.from(
            listRef.current.querySelectorAll<HTMLElement>(
                '[data-pin-entity-id]',
            ),
        ).find(
            (element) =>
                element.dataset.pinEntityId === pendingPinFocus.entityId,
        );
        const viewAllLink = listRef.current.querySelector<HTMLElement>(
            '[data-sidebar-entity-view-all]',
        );
        (movedStar ?? viewAllLink)?.focus();
    }, [pinned, needsAttention]);

    const togglePin = onTogglePin
        ? (entity: SidebarEntity, isPinned: boolean) => {
              pendingPinFocusRef.current = {
                  entityId: entity.id,
                  wasPinned: isPinned,
              };
              onTogglePin(entity);
          }
        : undefined;
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
        <SidebarMenuSub ref={listRef}>
            <EntityBlock
                heading={t('sidebarEntities.pinned')}
                entities={visiblePinned}
                isPinnedBlock
                onTogglePin={togglePin}
                emptyText={
                    trimmedQuery === '' && shouldShowPinHint
                        ? t('sidebarEntities.pinHint')
                        : null
                }
            />
            {visibleAttention.length > 0 ? (
                <EntityBlock
                    heading={t('sidebarEntities.needsAttention')}
                    entities={visibleAttention}
                    isPinnedBlock={false}
                    onTogglePin={togglePin}
                />
            ) : null}
            <SidebarMenuSubItem>
                <SidebarMenuSubButton asChild size="sm">
                    <Link href={viewAllHref} data-sidebar-entity-view-all="">
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
    isPinnedBlock,
    onTogglePin,
    emptyText = null,
}: {
    heading: string;
    entities: SidebarEntity[];
    /** Pinned rows offer "Unpin", Needs attention rows offer "Pin". */
    isPinnedBlock: boolean;
    onTogglePin?: (entity: SidebarEntity, isPinned: boolean) => void;
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
                        <EntityRow
                            key={entity.id}
                            entity={entity}
                            isPinned={isPinnedBlock}
                            onTogglePin={onTogglePin}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * One record: its link and, with `onTogglePin`, the star at the right of the
 * row plus a right-click menu with the same action. The star sits after the
 * link in the tab order, so Tab from a record reaches its star.
 */
function EntityRow({
    entity,
    isPinned,
    onTogglePin,
}: {
    entity: SidebarEntity;
    isPinned: boolean;
    onTogglePin?: (entity: SidebarEntity, isPinned: boolean) => void;
}) {
    const { t } = useTranslation();
    const entityLink = (
        <SidebarMenuSubButton
            asChild
            isActive={entity.isActive}
            className={cn(onTogglePin && 'pr-8')}
        >
            <Link
                href={entity.href}
                aria-current={entity.isActive ? 'page' : undefined}
            >
                <EntityStatus status={entity.status} />
                {entity.leading}
                <span>{entity.label}</span>
            </Link>
        </SidebarMenuSubButton>
    );

    if (!onTogglePin) {
        return <SidebarMenuSubItem>{entityLink}</SidebarMenuSubItem>;
    }

    const label = pinActionLabel(t, isPinned, entity.label);
    const toggle = () => onTogglePin(entity, isPinned);

    return (
        <ContextMenu modal={false}>
            <ContextMenuTrigger asChild>
                <SidebarMenuSubItem>
                    {entityLink}
                    {/* 24px, centred in the 28px row (WCAG 2.2 target size).
                        Hidden until the row is hovered, focused or has its
                        menu open; always shown where there is no hover. */}
                    <SidebarMenuAction
                        type="button"
                        aria-label={label}
                        aria-pressed={isPinned}
                        title={label}
                        data-pin-entity-id={entity.id}
                        onClick={toggle}
                        className="top-0.5 w-6 opacity-0 group-focus-within/menu-sub-item:opacity-100 group-hover/menu-sub-item:opacity-100 group-data-[state=open]/menu-sub-item:opacity-100 pointer-coarse:opacity-100"
                    >
                        <Star
                            aria-hidden="true"
                            className={cn(
                                isPinned && 'fill-current text-sidebar-primary',
                            )}
                        />
                    </SidebarMenuAction>
                </SidebarMenuSubItem>
            </ContextMenuTrigger>
            <ContextMenuContent>
                <ContextMenuItem onSelect={toggle}>
                    <Star aria-hidden="true" />
                    {label}
                </ContextMenuItem>
            </ContextMenuContent>
        </ContextMenu>
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
    onTogglePin,
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
        {
            heading: t('sidebarEntities.pinned'),
            entities: pinned,
            isPinned: true,
        },
        {
            heading: t('sidebarEntities.needsAttention'),
            entities: attention,
            isPinned: false,
        },
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
                            // The star is a menu item of its own, right of
                            // the record: arrow keys reach it after the
                            // record, and choosing it closes the menu.
                            <div
                                key={entity.id}
                                role="none"
                                className="flex items-center gap-0.5"
                            >
                                <DropdownMenuItem
                                    asChild
                                    className="min-w-0 flex-1"
                                >
                                    <Link
                                        href={entity.href}
                                        aria-current={
                                            entity.isActive ? 'page' : undefined
                                        }
                                    >
                                        <EntityStatus status={entity.status} />
                                        {entity.leading}
                                        <span className="truncate">
                                            {entity.label}
                                        </span>
                                    </Link>
                                </DropdownMenuItem>
                                {onTogglePin ? (
                                    <FlyoutPinItem
                                        entity={entity}
                                        isPinned={block.isPinned}
                                        onTogglePin={onTogglePin}
                                    />
                                ) : null}
                            </div>
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

/** The flyout's star for one record ("Pin …" / "Unpin …"). */
function FlyoutPinItem({
    entity,
    isPinned,
    onTogglePin,
}: {
    entity: SidebarEntity;
    isPinned: boolean;
    onTogglePin: (entity: SidebarEntity) => void;
}) {
    const { t } = useTranslation();
    const label = pinActionLabel(t, isPinned, entity.label);

    return (
        <DropdownMenuItem
            aria-label={label}
            title={label}
            className="shrink-0"
            onSelect={() => onTogglePin(entity)}
        >
            <Star
                aria-hidden="true"
                className={cn(isPinned && 'fill-current text-primary')}
            />
        </DropdownMenuItem>
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
    const label = pinActionLabel(t, isPinned, entityLabel);

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
