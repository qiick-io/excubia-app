import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PinToggleButton } from '@/components/sidebar-entity-list';
import { toggleProjectPin } from '@/lib/project-pins';
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

    return (
        <PinToggleButton
            isPinned={isPinned}
            entityLabel={project.name}
            disabled={isSaving}
            onToggle={() =>
                toggleProjectPin(project.slug, isPinned, {
                    onStart: () => setIsSaving(true),
                    onFinish: () => setIsSaving(false),
                })
            }
        />
    );
}
