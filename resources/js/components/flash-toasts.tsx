import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from '@/lib/toast';
import type { FlashToast } from '@/types';

/**
 * Surfaces the server's `Inertia::flash('toast', ...)` as a toast after Inertia visits.
 */
export function FlashToasts() {
    useEffect(() => {
        return router.on('flash', (event) => {
            const data = (event as CustomEvent).detail?.flash?.toast as
                | FlashToast
                | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);

    return null;
}
