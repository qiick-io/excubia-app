/** Custom event that opens or closes the AI assistant panel (sidebar item, ⌘J). */
export const AI_TOGGLE_EVENT = 'ai:toggle';

/**
 * Open or close the AI assistant panel on the current page, keeping its thread.
 */
export function toggleAiAssistant(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new Event(AI_TOGGLE_EVENT));
}
