import { useState } from 'react';
import type { FocusEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useShortcut } from '@/components/keyboard-shortcuts';

/** Elements whose own Enter behaviour wins over "open the active row". */
const CONTROL_SELECTOR =
    'a[href], button, [role="button"], [role="menuitem"], [role="checkbox"], summary';

type ListAction<TRow> = {
    /** One letter (`e`, `a`). */
    keys: string;
    /** Shown in the `?` help dialog, already translated. */
    description: string;
    handler: (row: TRow) => void;
    isEnabled?: boolean;
};

type UseListShortcutsOptions<TRow> = {
    rows: TRow[];
    getRowId: (row: TRow) => string;
    onOpen: (row: TRow) => void;
    onToggleSelect?: (row: TRow) => void;
    /** The list's main action on the active row, e.g. `e` resolve. */
    primaryAction?: ListAction<TRow>;
    /** A second action on the active row, e.g. `a` assign to me. */
    secondaryAction?: ListAction<TRow>;
    /** False while a drawer or dialog is open, so the keys reach it instead. */
    isEnabled?: boolean;
};

/**
 * Keyboard triage for an inbox list (#277, app-ui optional pattern `keyboard-shortcuts`):
 * `j` / `k` move an active row, Enter opens it, `x` selects it, and the list maps up to two
 * letters to its own actions. The keys only work while the list is mounted and no drawer is
 * open, and `ShortcutsProvider` turns them off while typing or when the user disabled
 * single-key shortcuts. Every action stays reachable with the mouse.
 *
 * Returns the active row's id and the props that mark it in the table.
 */
export function useListShortcuts<TRow>({
    rows,
    getRowId,
    onOpen,
    onToggleSelect,
    primaryAction,
    secondaryAction,
    isEnabled = true,
}: UseListShortcutsOptions<TRow>) {
    const { t } = useTranslation();
    const [activeId, setActiveId] = useState<string | null>(null);
    const activeIndex = rows.findIndex((row) => getRowId(row) === activeId);
    const activeRow = activeIndex === -1 ? null : rows[activeIndex];
    const group = t('shortcuts.groupList');
    const isListEnabled = isEnabled && rows.length > 0;

    const moveTo = (index: number): void => {
        const row = rows[Math.max(0, Math.min(rows.length - 1, index))];

        if (row === undefined) {
            return;
        }

        const id = getRowId(row);
        setActiveId(id);
        const element = document.querySelector<HTMLElement>(
            `[data-list-row-id="${CSS.escape(id)}"]`,
        );
        element?.scrollIntoView({ block: 'nearest' });

        // A row the table opens itself (DataTable `onRowClick`) is focusable: move focus with
        // the active row, so Tab, Enter and the letter keys all act on the same row (#424).
        if (element !== null && element.tabIndex >= 0) {
            element.focus({ preventScroll: true });
        }
    };

    /**
     * Enter opens the active row, except where Enter already means something: on a focused
     * control it activates that control (the shortcut provider prevented the native press), and
     * on a focused row the table has opened it already.
     */
    const handleEnter = (): void => {
        const focused = document.activeElement;

        if (
            focused instanceof HTMLElement &&
            focused.matches(CONTROL_SELECTOR)
        ) {
            focused.click();

            return;
        }

        if (
            focused instanceof HTMLElement &&
            focused.dataset.listRowId !== undefined &&
            focused.tabIndex >= 0
        ) {
            return;
        }

        if (activeRow !== null) {
            onOpen(activeRow);
        }
    };

    useShortcut({
        keys: 'j',
        description: t('listShortcuts.next'),
        group,
        isEnabled: isListEnabled,
        handler: () => moveTo(activeIndex + 1),
    });
    useShortcut({
        keys: 'k',
        description: t('listShortcuts.previous'),
        group,
        isEnabled: isListEnabled,
        handler: () => moveTo(activeIndex === -1 ? 0 : activeIndex - 1),
    });
    useShortcut({
        keys: 'enter',
        description: t('listShortcuts.open'),
        group,
        isEnabled: isListEnabled && activeRow !== null,
        handler: handleEnter,
    });
    useShortcut({
        keys: 'x',
        description: t('listShortcuts.select'),
        group,
        isEnabled:
            isListEnabled && activeRow !== null && onToggleSelect !== undefined,
        handler: () => activeRow !== null && onToggleSelect?.(activeRow),
    });
    useShortcut({
        keys: primaryAction?.keys ?? 'e',
        description: primaryAction?.description ?? '',
        group,
        isEnabled:
            isListEnabled &&
            activeRow !== null &&
            primaryAction !== undefined &&
            primaryAction.isEnabled !== false,
        handler: () => activeRow !== null && primaryAction?.handler(activeRow),
    });
    useShortcut({
        keys: secondaryAction?.keys ?? 'a',
        description: secondaryAction?.description ?? '',
        group,
        isEnabled:
            isListEnabled &&
            activeRow !== null &&
            secondaryAction !== undefined &&
            secondaryAction.isEnabled !== false,
        handler: () =>
            activeRow !== null && secondaryAction?.handler(activeRow),
    });

    return {
        activeId,
        getActiveRowProps: (row: TRow) => {
            const id = getRowId(row);
            const isActive = id === activeId;

            return {
                'data-list-row-id': id,
                // Tab onto a row makes it the active one, so `x` and the actions follow focus.
                // Only the row itself: focus moving to a control inside it changes nothing.
                // Keyboard focus only: a re-render on a mouse press would replace the pressed
                // cell before the click, so the row would never open.
                onFocus: (event: FocusEvent<HTMLElement>) => {
                    if (
                        event.target === event.currentTarget &&
                        event.currentTarget.matches(':focus-visible')
                    ) {
                        setActiveId(id);
                    }
                },
                'data-active': isActive ? 'true' : undefined,
                'aria-current': isActive ? ('true' as const) : undefined,
                // An outline on the active row: the selection keeps its own background.
                className: isActive
                    ? 'outline-2 -outline-offset-2 outline-ring'
                    : undefined,
            };
        },
    };
}
