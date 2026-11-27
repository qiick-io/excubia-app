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
            const target = openerRef.current?.isConnected
                ? openerRef.current
                : document.querySelector<HTMLElement>(fallbackSelector);
            openerRef.current = null;

            if (target === null) {
                return;
            }

            event.preventDefault();
            target.focus({ preventScroll: true });
        },
    };
}
