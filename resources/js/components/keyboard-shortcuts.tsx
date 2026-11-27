import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import {
    RESERVED_SHORTCUTS,
    isSingleKeyShortcut,
    isTypingTarget,
    matchesShortcut,
    shortcutKeyCaps,
} from '@/lib/shortcut-keys';

/**
 * Keyboard shortcuts for the qiick apps (optional pattern
 * `keyboard-shortcuts`, docs/optional/keyboard-shortcuts.md).
 *
 * One document listener dispatches every shortcut a mounted component
 * registers with `useShortcut`, so shortcuts are scoped by what is on screen:
 * a list registers `j` / `k` while it is mounted, and the most recently
 * mounted registration of the same keys wins (a page over the shell).
 *
 * Rules the listener enforces, so no screen has to:
 * - a key press another handler already took (`defaultPrevented`, e.g. ⌘K
 *   inside a rich-text editor that inserts a link) is left alone;
 * - single-key shortcuts never fire while the person types, and not at all
 *   when the user turned them off (WCAG 2.1.4);
 * - modifier shortcuts fire in plain fields, but not inside a rich-text editor.
 *
 * `?` opens the help dialog listing every active shortcut.
 */

type ShortcutRegistration = {
    id: string;
    keys: string;
    description: string;
    group: string;
    handler: (event: KeyboardEvent) => void;
};

type ShortcutsContextValue = {
    register: (registration: ShortcutRegistration) => () => void;
    registrations: ShortcutRegistration[];
    isApplePlatform: boolean;
};

const ShortcutsContext = createContext<ShortcutsContextValue | null>(null);

/** `mod` means ⌘ on Apple platforms and Ctrl elsewhere. */
function detectApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return /mac|iphone|ipad|ipod/i.test(navigator.userAgent);
}

type ShortcutsProviderProps = {
    children: ReactNode;
    /**
     * The user's setting: false turns off every single-key shortcut (`j`,
     * `e`, `?`); ⌘ / Ctrl shortcuts keep working.
     */
    canUseSingleKeyShortcuts?: boolean;
};

/**
 * Wrap the authenticated app once (next to `TooltipProvider`).
 */
export function ShortcutsProvider({
    children,
    canUseSingleKeyShortcuts = true,
}: ShortcutsProviderProps) {
    const [registrations, setRegistrations] = useState<ShortcutRegistration[]>(
        [],
    );
    const registrationsRef = useRef<ShortcutRegistration[]>([]);
    const [isHelpOpen, setIsHelpOpen] = useState(false);
    const isApplePlatform = useMemo(() => detectApplePlatform(), []);

    const register = useCallback((registration: ShortcutRegistration) => {
        registrationsRef.current = [
            ...registrationsRef.current.filter(
                (existing) => existing.id !== registration.id,
            ),
            registration,
        ];
        setRegistrations(registrationsRef.current);

        return () => {
            registrationsRef.current = registrationsRef.current.filter(
                (existing) => existing.id !== registration.id,
            );
            setRegistrations(registrationsRef.current);
        };
    }, []);

    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.defaultPrevented || event.isComposing) {
                return;
            }

            const isTyping = isTypingTarget(event.target);
            const isInEditor =
                event.target instanceof HTMLElement &&
                event.target.isContentEditable;
            const candidates = [...registrationsRef.current].reverse();

            for (const registration of candidates) {
                if (
                    !matchesShortcut(event, registration.keys, isApplePlatform)
                ) {
                    continue;
                }

                if (isSingleKeyShortcut(registration.keys)) {
                    if (!canUseSingleKeyShortcuts || isTyping) {
                        return;
                    }
                } else if (isInEditor) {
                    return;
                }

                event.preventDefault();
                registration.handler(event);

                return;
            }
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [canUseSingleKeyShortcuts, isApplePlatform]);

    const contextValue = useMemo(
        () => ({ register, registrations, isApplePlatform }),
        [register, registrations, isApplePlatform],
    );

    return (
        <ShortcutsContext.Provider value={contextValue}>
            <HelpShortcut onOpen={() => setIsHelpOpen(true)} />
            {children}
            <ShortcutsHelpDialog
                open={isHelpOpen}
                onOpenChange={setIsHelpOpen}
                canUseSingleKeyShortcuts={canUseSingleKeyShortcuts}
            />
        </ShortcutsContext.Provider>
    );
}

function useShortcutsContext(): ShortcutsContextValue {
    const contextValue = useContext(ShortcutsContext);

    if (!contextValue) {
        throw new Error('useShortcut needs a ShortcutsProvider above it.');
    }

    return contextValue;
}

type UseShortcutOptions = {
    /** `mod+k`, `shift+?`, `j`: see `lib/shortcut-keys.ts`. */
    keys: string;
    /** Shown in the help dialog, already translated. */
    description: string;
    /** Help dialog section, already translated (e.g. "Navigation", "List"). */
    group: string;
    handler: (event: KeyboardEvent) => void;
    /** False keeps the shortcut out while, say, a list has no rows. */
    isEnabled?: boolean;
};

/**
 * Registers a shortcut while the calling component is mounted. The handler
 * may change on every render: the latest one runs.
 */
export function useShortcut({
    keys,
    description,
    group,
    handler,
    isEnabled = true,
}: UseShortcutOptions): void {
    const { register } = useShortcutsContext();
    const id = useId();
    const handlerRef = useRef(handler);

    useEffect(() => {
        handlerRef.current = handler;
    });

    useEffect(() => {
        if (!isEnabled) {
            return;
        }

        return register({
            id,
            keys,
            description,
            group,
            handler: (event) => handlerRef.current(event),
        });
    }, [register, id, keys, description, group, isEnabled]);
}

/** The key caps of a definition on this platform, for hints next to a control. */
export function useShortcutKeyCaps(keys: string): string[] {
    const { isApplePlatform } = useShortcutsContext();

    return useMemo(
        () => shortcutKeyCaps(keys, isApplePlatform),
        [keys, isApplePlatform],
    );
}

/** A shortcut hint (`⌘ K`) next to a control or in a tooltip. */
export function ShortcutHint({
    keys,
    className,
}: {
    keys: string;
    className?: string;
}) {
    const keyCaps = useShortcutKeyCaps(keys);

    return (
        <KbdGroup className={className} aria-hidden="true">
            {keyCaps.map((keyCap) => (
                <Kbd key={keyCap}>{keyCap}</Kbd>
            ))}
        </KbdGroup>
    );
}

function HelpShortcut({ onOpen }: { onOpen: () => void }) {
    const { t } = useTranslation();

    useShortcut({
        keys: RESERVED_SHORTCUTS.shortcutsHelp,
        description: t('shortcuts.showHelp'),
        group: t('shortcuts.groupGeneral'),
        handler: onOpen,
    });

    return null;
}

type ShortcutsHelpDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canUseSingleKeyShortcuts: boolean;
};

/**
 * Lists the shortcuts active on this screen, by group. Opened with `?`.
 */
function ShortcutsHelpDialog({
    open,
    onOpenChange,
    canUseSingleKeyShortcuts,
}: ShortcutsHelpDialogProps) {
    const { t } = useTranslation();
    const { registrations, isApplePlatform } = useShortcutsContext();
    const registrationsByGroup = useMemo(() => {
        const groups = new Map<string, ShortcutRegistration[]>();
        const seenKeys = new Set<string>();

        // The latest registration of a key is the one that fires.
        for (const registration of [...registrations].reverse()) {
            if (seenKeys.has(registration.keys)) {
                continue;
            }

            if (
                !canUseSingleKeyShortcuts &&
                isSingleKeyShortcut(registration.keys)
            ) {
                continue;
            }

            seenKeys.add(registration.keys);
            groups.set(registration.group, [
                ...(groups.get(registration.group) ?? []),
                registration,
            ]);
        }

        return [...groups.entries()].reverse();
    }, [registrations, canUseSingleKeyShortcuts]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('shortcuts.title')}</DialogTitle>
                    <DialogDescription>
                        {canUseSingleKeyShortcuts
                            ? t('shortcuts.description')
                            : t('shortcuts.singleKeyOff')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    {registrationsByGroup.map(([group, groupRegistrations]) => (
                        <section key={group} className="grid gap-2">
                            <h3 className="text-xs font-medium text-muted-foreground">
                                {group}
                            </h3>
                            <dl className="grid gap-1.5">
                                {[...groupRegistrations]
                                    .reverse()
                                    .map((registration) => (
                                        <div
                                            key={registration.id}
                                            className="flex items-center justify-between gap-4 text-sm"
                                        >
                                            <dt>{registration.description}</dt>
                                            <dd>
                                                <KbdGroup>
                                                    {shortcutKeyCaps(
                                                        registration.keys,
                                                        isApplePlatform,
                                                    ).map((keyCap) => (
                                                        <Kbd key={keyCap}>
                                                            {keyCap}
                                                        </Kbd>
                                                    ))}
                                                </KbdGroup>
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                        </section>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
