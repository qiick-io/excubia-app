import { useSyncExternalStore } from 'react';

type AccessibilityPreferences = {
    readonly highContrastEnabled: boolean;
    readonly reduceMotionEnabled: boolean;
    /** Compact table rows (#277, app-ui optional pattern `table-density`). */
    readonly compactTablesEnabled: boolean;
    /** Single-key shortcuts (`j`, `e`, `?`) turned off (#277, WCAG 2.1.4); ⌘ / Ctrl ones still work. */
    readonly singleKeyShortcutsDisabled: boolean;
};

export type UseAccessibilityPreferencesReturn = AccessibilityPreferences & {
    readonly enabledCount: number;
    readonly updateHighContrast: (enabled: boolean) => void;
    readonly updateReduceMotion: (enabled: boolean) => void;
    readonly toggleHighContrast: () => void;
    readonly toggleReduceMotion: () => void;
    readonly toggleCompactTables: () => void;
    readonly toggleSingleKeyShortcuts: () => void;
};

const HIGH_CONTRAST_STORAGE_KEY = 'accessibility_high_contrast';
const REDUCE_MOTION_STORAGE_KEY = 'accessibility_reduce_motion';
const HIGH_CONTRAST_COOKIE = 'accessibility_high_contrast';
const REDUCE_MOTION_COOKIE = 'accessibility_reduce_motion';
const COMPACT_TABLES_STORAGE_KEY = 'table_density_compact';
const SINGLE_KEY_SHORTCUTS_OFF_STORAGE_KEY = 'shortcuts_single_key_off';
const COMPACT_TABLES_COOKIE = 'table_density_compact';
const HIGH_CONTRAST_CLASS = 'high-contrast';
const REDUCE_MOTION_CLASS = 'reduce-motion';

const listeners = new Set<() => void>();

const DEFAULT_PREFERENCES: AccessibilityPreferences = {
    highContrastEnabled: false,
    reduceMotionEnabled: false,
    compactTablesEnabled: false,
    singleKeyShortcutsDisabled: false,
};

let currentPreferences: AccessibilityPreferences = DEFAULT_PREFERENCES;

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const readFlag = (storageKey: string): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return localStorage.getItem(storageKey) === '1';
};

const applyClasses = (preferences: AccessibilityPreferences): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle(
        HIGH_CONTRAST_CLASS,
        preferences.highContrastEnabled,
    );
    // OS `prefers-reduced-motion` always wins in CSS; this class forces reduce
    // when the in-app toggle is on and the OS setting is off.
    document.documentElement.classList.toggle(
        REDUCE_MOTION_CLASS,
        preferences.reduceMotionEnabled,
    );
    // The theme tightens table rows under `data-density="compact"` (app-ui `table-density`).
    document.documentElement.setAttribute(
        'data-density',
        preferences.compactTablesEnabled ? 'compact' : 'comfortable',
    );
};

const persistFlag = (
    cookieName: string | null,
    storageKey: string,
    enabled: boolean,
): void => {
    if (typeof window === 'undefined') {
        return;
    }

    if (enabled) {
        localStorage.setItem(storageKey, '1');
    } else {
        localStorage.removeItem(storageKey);
    }

    // Only the flags Blade stamps before paint need a cookie.
    if (cookieName !== null) {
        setCookie(cookieName, enabled ? '1' : '0');
    }
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const setPreferences = (next: AccessibilityPreferences): void => {
    currentPreferences = next;
    applyClasses(next);
    notify();
};

/**
 * Applies stored accessibility preferences (no localStorage write).
 * Call once from `app.tsx` after Inertia props are available.
 */
export function initializeAccessibilityPreferences(): void {
    if (typeof window === 'undefined') {
        return;
    }

    setPreferences({
        highContrastEnabled: readFlag(HIGH_CONTRAST_STORAGE_KEY),
        reduceMotionEnabled: readFlag(REDUCE_MOTION_STORAGE_KEY),
        compactTablesEnabled: readFlag(COMPACT_TABLES_STORAGE_KEY),
        singleKeyShortcutsDisabled: readFlag(
            SINGLE_KEY_SHORTCUTS_OFF_STORAGE_KEY,
        ),
    });
}

/**
 * Reads and updates high-contrast / reduce-motion preferences.
 * Persists to `localStorage` and cookies for Blade pre-paint classes.
 */
export function useAccessibilityPreferences(): UseAccessibilityPreferencesReturn {
    const preferences = useSyncExternalStore(
        subscribe,
        () => currentPreferences,
        () => DEFAULT_PREFERENCES,
    );

    const updateHighContrast = (enabled: boolean): void => {
        persistFlag(HIGH_CONTRAST_COOKIE, HIGH_CONTRAST_STORAGE_KEY, enabled);
        setPreferences({
            ...currentPreferences,
            highContrastEnabled: enabled,
        });
    };

    const updateReduceMotion = (enabled: boolean): void => {
        persistFlag(REDUCE_MOTION_COOKIE, REDUCE_MOTION_STORAGE_KEY, enabled);
        setPreferences({
            ...currentPreferences,
            reduceMotionEnabled: enabled,
        });
    };

    const updateCompactTables = (enabled: boolean): void => {
        persistFlag(COMPACT_TABLES_COOKIE, COMPACT_TABLES_STORAGE_KEY, enabled);
        setPreferences({
            ...currentPreferences,
            compactTablesEnabled: enabled,
        });
    };

    const updateSingleKeyShortcutsDisabled = (disabled: boolean): void => {
        persistFlag(null, SINGLE_KEY_SHORTCUTS_OFF_STORAGE_KEY, disabled);
        setPreferences({
            ...currentPreferences,
            singleKeyShortcutsDisabled: disabled,
        });
    };

    const enabledCount =
        Number(preferences.highContrastEnabled) +
        Number(preferences.reduceMotionEnabled);

    return {
        highContrastEnabled: preferences.highContrastEnabled,
        reduceMotionEnabled: preferences.reduceMotionEnabled,
        compactTablesEnabled: preferences.compactTablesEnabled,
        singleKeyShortcutsDisabled: preferences.singleKeyShortcutsDisabled,
        enabledCount,
        updateHighContrast,
        updateReduceMotion,
        toggleHighContrast: () =>
            updateHighContrast(!currentPreferences.highContrastEnabled),
        toggleReduceMotion: () =>
            updateReduceMotion(!currentPreferences.reduceMotionEnabled),
        toggleCompactTables: () =>
            updateCompactTables(!currentPreferences.compactTablesEnabled),
        toggleSingleKeyShortcuts: () =>
            updateSingleKeyShortcutsDisabled(
                !currentPreferences.singleKeyShortcutsDisabled,
            ),
    } as const;
}
