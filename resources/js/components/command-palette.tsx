import { Clock, Search } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ShortcutHint, useShortcut } from '@/components/keyboard-shortcuts';
import { Button } from '@/components/ui/button';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { RESERVED_SHORTCUTS } from '@/lib/shortcut-keys';
import { cn } from '@/lib/utils';

/**
 * Command palette and global search (optional pattern `command-palette`,
 * docs/optional/command-palette.md).
 *
 * One dialog, opened with ⌘K / Ctrl+K or the header trigger, that reaches any
 * page, record or action by name. The app passes two kinds of results:
 * - `groups`: what the browser already knows (navigation, actions), filtered
 *   locally as the person types;
 * - `search`: an async function for records only the server can find,
 *   debounced, with stale requests aborted.
 * Recently opened results are kept per browser, so the empty palette is
 * already useful.
 */

export type CommandPaletteItem = {
    /** Unique across all groups (`project:42`, `nav:settings`). */
    id: string;
    label: string;
    /** Second line: the record type, its parent, an email. */
    description?: string;
    icon?: LucideIcon;
    /** Trailing content, such as a status badge. */
    meta?: ReactNode;
    /** Extra words the local filter matches (aliases, codes). */
    keywords?: string[];
    /** A link result: opened with `onNavigate`, ⌘ / Ctrl+Enter in a new tab. */
    href?: string;
    /** An action result; runs instead of navigating. */
    onSelect?: () => void;
};

export type CommandPaletteGroup = {
    id: string;
    /** Already translated. */
    heading: string;
    items: CommandPaletteItem[];
};

type RecentItem = Pick<
    CommandPaletteItem,
    'id' | 'label' | 'description' | 'href'
>;

const RECENT_LIMIT = 5;
const SEARCH_DEBOUNCE_MILLISECONDS = 250;
const SEARCH_MIN_LENGTH = 2;

/** Recent results live in localStorage, which may be blocked or full. */
function readRecentItems(storageKey: string): RecentItem[] {
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) ?? '[]');

        return Array.isArray(stored) ? stored.slice(0, RECENT_LIMIT) : [];
    } catch {
        return [];
    }
}

function writeRecentItems(storageKey: string, recentItems: RecentItem[]) {
    try {
        localStorage.setItem(storageKey, JSON.stringify(recentItems));
    } catch {
        // A blocked or full storage only loses the recent list.
    }
}

type CommandPaletteProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Local results, filtered as the person types. */
    groups: CommandPaletteGroup[];
    /**
     * Server results for a query of at least two characters. It must return
     * only what the current user may open, and honour the abort signal.
     */
    search?: (
        query: string,
        signal: AbortSignal,
    ) => Promise<CommandPaletteGroup[]>;
    /** The app's navigation (Inertia `router.visit`). */
    onNavigate: (href: string) => void;
    /** localStorage key for recent results; per app, e.g. `excubia.palette.recent`. */
    recentStorageKey: string;
};

/**
 * The palette dialog. Mount it once in the authenticated layout, inside
 * `ShortcutsProvider`; it registers ⌘K / Ctrl+K itself.
 */
export function CommandPalette({
    open,
    onOpenChange,
    groups,
    search,
    onNavigate,
    recentStorageKey,
}: CommandPaletteProps) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [remoteGroups, setRemoteGroups] = useState<CommandPaletteGroup[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const [hasSearchFailed, setHasSearchFailed] = useState(false);
    const [recentItems, setRecentItems] = useState<RecentItem[]>([]);
    const shouldOpenInNewTabRef = useRef(false);

    useShortcut({
        keys: RESERVED_SHORTCUTS.commandPalette,
        description: t('commandPalette.open'),
        group: t('shortcuts.groupGeneral'),
        handler: () => onOpenChange(!open),
    });

    useEffect(() => {
        if (open) {
            setRecentItems(readRecentItems(recentStorageKey));
        } else {
            setQuery('');
        }
    }, [open, recentStorageKey]);

    const trimmedQuery = query.trim();
    const shouldSearchRemotely =
        search !== undefined && trimmedQuery.length >= SEARCH_MIN_LENGTH;

    useEffect(() => {
        if (!shouldSearchRemotely) {
            setRemoteGroups([]);
            setIsSearching(false);
            setHasSearchFailed(false);

            return;
        }

        const abortController = new AbortController();
        setIsSearching(true);
        const timeout = window.setTimeout(() => {
            search(trimmedQuery, abortController.signal)
                .then((foundGroups) => {
                    setRemoteGroups(foundGroups);
                    setHasSearchFailed(false);
                })
                .catch(() => {
                    // The palette says the search failed; local results stay.
                    if (!abortController.signal.aborted) {
                        setRemoteGroups([]);
                        setHasSearchFailed(true);
                    }
                })
                .finally(() => {
                    if (!abortController.signal.aborted) {
                        setIsSearching(false);
                    }
                });
        }, SEARCH_DEBOUNCE_MILLISECONDS);

        return () => {
            window.clearTimeout(timeout);
            abortController.abort();
        };
    }, [search, shouldSearchRemotely, trimmedQuery]);

    const selectItem = (item: CommandPaletteItem | RecentItem) => {
        const shouldOpenInNewTab = shouldOpenInNewTabRef.current;
        shouldOpenInNewTabRef.current = false;

        if ('onSelect' in item && item.onSelect) {
            onOpenChange(false);
            item.onSelect();

            return;
        }

        if (!item.href) {
            return;
        }

        const recentItem: RecentItem = {
            id: item.id,
            label: item.label,
            description: item.description,
            href: item.href,
        };
        writeRecentItems(
            recentStorageKey,
            [
                recentItem,
                ...readRecentItems(recentStorageKey).filter(
                    (existing) => existing.id !== item.id,
                ),
            ].slice(0, RECENT_LIMIT),
        );

        if (shouldOpenInNewTab) {
            window.open(item.href, '_blank', 'noopener');

            return;
        }

        onOpenChange(false);
        onNavigate(item.href);
    };

    // Server results already match the query; the keyword keeps the local
    // filter from hiding a result whose label matches only loosely.
    const remoteGroupsWithQuery = useMemo(
        () =>
            remoteGroups.map((group) => ({
                ...group,
                items: group.items.map((item) => ({
                    ...item,
                    keywords: [...(item.keywords ?? []), trimmedQuery],
                })),
            })),
        [remoteGroups, trimmedQuery],
    );

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('commandPalette.title')}
            description={t('commandPalette.description')}
            className="sm:max-w-xl"
        >
            <CommandInput
                value={query}
                onValueChange={setQuery}
                placeholder={t('commandPalette.placeholder')}
                onKeyDown={(event) => {
                    // Focus stays in the input while arrows move the
                    // selection, so Enter arrives here before cmdk selects.
                    if (event.key === 'Enter') {
                        shouldOpenInNewTabRef.current =
                            event.metaKey || event.ctrlKey;
                    }
                }}
            />
            <CommandList className="max-h-[min(60vh,420px)]">
                <CommandEmpty>
                    {isSearching
                        ? t('commandPalette.searching')
                        : t('commandPalette.empty')}
                </CommandEmpty>
                {trimmedQuery === '' && recentItems.length > 0 ? (
                    <CommandGroup heading={t('commandPalette.recent')}>
                        {recentItems.map((item) => (
                            <PaletteRow
                                key={`recent:${item.id}`}
                                item={{ ...item, icon: Clock }}
                                value={`recent:${item.id}`}
                                onSelect={() => selectItem(item)}
                            />
                        ))}
                    </CommandGroup>
                ) : null}
                {[...groups, ...remoteGroupsWithQuery].map((group) =>
                    group.items.length > 0 ? (
                        <CommandGroup key={group.id} heading={group.heading}>
                            {group.items.map((item) => (
                                <PaletteRow
                                    key={item.id}
                                    item={item}
                                    value={item.id}
                                    onSelect={() => selectItem(item)}
                                />
                            ))}
                        </CommandGroup>
                    ) : null,
                )}
                {shouldSearchRemotely && (isSearching || hasSearchFailed) ? (
                    <p
                        role="status"
                        className="px-3 py-2 text-xs text-muted-foreground"
                    >
                        {hasSearchFailed
                            ? t('commandPalette.searchFailed')
                            : t('commandPalette.searching')}
                    </p>
                ) : null}
            </CommandList>
        </CommandDialog>
    );
}

function PaletteRow({
    item,
    value,
    onSelect,
}: {
    item: CommandPaletteItem;
    value: string;
    onSelect: () => void;
}) {
    const Icon = item.icon;

    return (
        <CommandItem
            value={value}
            keywords={[
                item.label,
                ...(item.description ? [item.description] : []),
                ...(item.keywords ?? []),
            ]}
            onSelect={onSelect}
        >
            {Icon ? <Icon aria-hidden="true" /> : null}
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate">{item.label}</span>
                {item.description ? (
                    <span className="truncate text-xs text-muted-foreground">
                        {item.description}
                    </span>
                ) : null}
            </span>
            {item.meta ? <span className="shrink-0">{item.meta}</span> : null}
        </CommandItem>
    );
}

/**
 * The header control that opens the palette, on the left of the header
 * actions: a search field look with the ⌘K hint, and an icon-only button
 * below `sm`.
 */
export function CommandPaletteTrigger({
    onOpen,
    className,
}: {
    onOpen: () => void;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <Button
            type="button"
            variant="outline"
            onClick={onOpen}
            aria-label={t('commandPalette.trigger')}
            aria-haspopup="dialog"
            className={cn(
                'w-8 justify-start gap-2 px-0 text-muted-foreground sm:w-56 sm:px-3',
                className,
            )}
        >
            <Search className="mx-auto size-4 sm:mx-0" aria-hidden="true" />
            <span className="hidden flex-1 text-left sm:inline">
                {t('commandPalette.trigger')}
            </span>
            <ShortcutHint
                keys={RESERVED_SHORTCUTS.commandPalette}
                className="hidden sm:inline-flex"
            />
        </Button>
    );
}
