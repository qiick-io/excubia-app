import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PinToggleButton } from '@/components/sidebar-entity-list';
import projectsRoutes from '@/routes/projects';
import type { ProjectRow } from '@/types';

/**
 * The star that pins a project to the sidebar (#273), on the projects list and the project
 * header. Whether it is pinned comes from the shared `sidebarProjects` prop, which the request
 * reloads, so the star and the sidebar never disagree.
 */
export function ProjectPinButton({
    project,
}: {
    project: Pick<ProjectRow, 'slug' | 'name'>;
}) {
    const { sidebarProjects } = usePage().props;
    const [isSaving, setIsSaving] = useState(false);
    const isPinned = sidebarProjects.pinned.some(
        (pinned) => pinned.slug === project.slug,
    );
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setIsSaving(true),
        onFinish: () => setIsSaving(false),
    };

    return (
        <PinToggleButton
            isPinned={isPinned}
            entityLabel={project.name}
            disabled={isSaving}
            onToggle={() =>
                isPinned
                    ? router.delete(
                          projectsRoutes.pin.destroy.url(project.slug),
                          options,
                      )
                    : router.post(
                          projectsRoutes.pin.store.url(project.slug),
                          {},
                          options,
                      )
            }
        />
    );
}
