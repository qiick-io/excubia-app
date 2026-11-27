import { router } from '@inertiajs/react';
import { toast } from '@/lib/toast';
import projectsRoutes from '@/routes/projects';

/**
 * Pins or unpins a project on the user's sidebar (#273, #294), from the project header, the
 * projects list and the sidebar rows. It reloads only the shared `sidebarProjects` prop, which
 * every star reads, and shows a refusal (the eight-pin limit) as an error toast.
 */
export function toggleProjectPin(
    slug: string,
    isPinned: boolean,
    options: { onStart?: () => void; onFinish?: () => void } = {},
): void {
    const visitOptions = {
        preserveScroll: true,
        preserveState: true,
        only: ['sidebarProjects'],
        onError: (errors: Record<string, string>) => {
            const [message] = Object.values(errors);

            if (message) {
                toast.error(message);
            }
        },
        ...options,
    };

    if (isPinned) {
        router.delete(projectsRoutes.pin.destroy.url(slug), visitOptions);

        return;
    }

    router.post(projectsRoutes.pin.store.url(slug), {}, visitOptions);
}
