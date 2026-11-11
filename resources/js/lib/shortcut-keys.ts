/**
 * Keyboard shortcut definitions as plain strings (`mod+k`, `shift+?`, `j`), and
 * the pure logic that matches them against a `KeyboardEvent`. It has no React
 * and no imports, so scripts can test it directly.
 *
 * `mod` is ⌘ on Apple platforms and Ctrl elsewhere, so a definition is written
 * once for every platform.
 */

/** Modifier names a definition may combine with one key. */
const MODIFIERS = ['mod', 'ctrl', 'alt', 'shift'] as const;

type ShortcutModifier = (typeof MODIFIERS)[number];

export type ParsedShortcut = {
    modifiers: Set<ShortcutModifier>;
    key: string;
};

/**
 * Shortcuts the qiick apps reserve for shared features. An app never binds
 * them to anything else, so the command palette and the Assistant panel open
 * the same way in every product.
 */
export const RESERVED_SHORTCUTS = {
    commandPalette: 'mod+k',
    assistantPanel: 'mod+j',
    shortcutsHelp: 'shift+?',
} as const;

/**
 * @param definition - `mod+k`, `shift+?`, `j`
 * @throws {Error} When a part is not a known modifier or there is no key
 */
export function parseShortcut(definition: string): ParsedShortcut {
    const parts = definition.toLowerCase().split('+');
    const key = parts.pop();

    if (!key) {
        throw new Error(`Shortcut "${definition}" has no key.`);
    }

    const modifiers = new Set<ShortcutModifier>();

    for (const part of parts) {
        if (!MODIFIERS.includes(part as ShortcutModifier)) {
            throw new Error(
                `Shortcut "${definition}": "${part}" is not a modifier.`,
            );
        }

        modifiers.add(part as ShortcutModifier);
    }

    return { modifiers, key };
}

/**
 * A single-key shortcut fires without ⌘ / Ctrl / Alt (Shift alone still
 * counts). Users can turn these off (WCAG 2.1.4), and they never fire while
 * the person types in a field.
 */
export function isSingleKeyShortcut(definition: string): boolean {
    const { modifiers } = parseShortcut(definition);

    return (
        !modifiers.has('mod') && !modifiers.has('ctrl') && !modifiers.has('alt')
    );
}

type ShortcutKeyEvent = Pick<
    KeyboardEvent,
    'key' | 'metaKey' | 'ctrlKey' | 'altKey' | 'shiftKey'
>;

/**
 * @param isApplePlatform - Whether `mod` means ⌘ (metaKey) rather than Ctrl
 */
export function matchesShortcut(
    event: ShortcutKeyEvent,
    definition: string,
    isApplePlatform: boolean,
): boolean {
    const { modifiers, key } = parseShortcut(definition);
    const shouldPressMeta = isApplePlatform && modifiers.has('mod');
    const shouldPressCtrl =
        modifiers.has('ctrl') || (!isApplePlatform && modifiers.has('mod'));

    if (
        event.metaKey !== shouldPressMeta ||
        event.ctrlKey !== shouldPressCtrl
    ) {
        return false;
    }

    if (event.altKey !== modifiers.has('alt')) {
        return false;
    }

    // Shift changes the printed key ("?" is Shift+/), so a definition that names
    // the printed key only requires Shift when it says so.
    if (modifiers.has('shift') && !event.shiftKey) {
        return false;
    }

    return event.key.toLowerCase() === key;
}

/**
 * The key caps to show for a definition: `['⌘', 'K']` on Apple platforms,
 * `['Ctrl', 'K']` elsewhere. Shift is dropped when the key is already a
 * shifted symbol (`shift+?` shows `?`).
 */
export function shortcutKeyCaps(
    definition: string,
    isApplePlatform: boolean,
): string[] {
    const { modifiers, key } = parseShortcut(definition);
    const isShiftedSymbol = key.length === 1 && !/[a-z0-9]/.test(key);
    const keyCaps: string[] = [];

    if (modifiers.has('mod')) {
        keyCaps.push(isApplePlatform ? '⌘' : 'Ctrl');
    }

    if (modifiers.has('ctrl')) {
        keyCaps.push(isApplePlatform ? '⌃' : 'Ctrl');
    }

    if (modifiers.has('alt')) {
        keyCaps.push(isApplePlatform ? '⌥' : 'Alt');
    }

    if (modifiers.has('shift') && !isShiftedSymbol) {
        keyCaps.push(isApplePlatform ? '⇧' : 'Shift');
    }

    keyCaps.push(key.length === 1 ? key.toUpperCase() : key);

    return keyCaps;
}

/**
 * Whether a key press comes from a place where the person is typing: a text
 * field, a select, or a rich-text editor (contenteditable). Single-key
 * shortcuts never fire there.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!target || typeof (target as Element).closest !== 'function') {
        return false;
    }

    const element = target as HTMLElement;

    if (element.isContentEditable) {
        return true;
    }

    return (
        element.closest(
            'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), textarea, select, [contenteditable=""], [contenteditable=true]',
        ) !== null
    );
}
