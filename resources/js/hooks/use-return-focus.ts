import { useRef } from 'react';

/**
 * Focus return for a dialog or drawer opened without a Radix trigger (#456): a keyboard
 * shortcut, a server response or a plain button. Remembers what had focus when it opened and
 * returns there on close; when that element is gone or was <body>, falls back to the page's
 * main content, so focus never ends on <body> (WCAG 2.4.3). Spread the handlers on the
 * `DialogContent` / `DrawerContent`; compose with an existing `onOpenAutoFocus` by calling
 * `rememberOpener()` first.
 */
export function useReturnFocus(fallbackSelector = '#main-content') {
    const openerRef = useRef<HTMLElement | null>(null);

    const rememberOpener = (): void => {
        const opener = document.activeElement;
        openerRef.current =
            opener instanceof HTMLElement && opener !== document.body
                ? opener
                : null;
    };

    return {
        rememberOpener,
        onOpenAutoFocus: rememberOpener,
        onCloseAutoFocus: (event: Event): void => {
            const opener = openerRef.current;
            openerRef.current = null;
            event.preventDefault();

            if (opener?.isConnected) {
                opener.focus({ preventScroll: true });
            }

            // The opener may still be in the page but hidden (a collapsed sidebar): then it
            // could not take focus, and the main content does.
            if (document.activeElement !== opener || opener === null) {
                document
                    .querySelector<HTMLElement>(fallbackSelector)
                    ?.focus({ preventScroll: true });
            }
        },
    };
}
