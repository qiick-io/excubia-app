import { usePage } from '@inertiajs/react';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * The uploaded logo for the current theme (Settings → Appearance): the dark logo in dark mode,
 * else the light one; null keeps the default mark. As in externa-core's AppLogo.
 */
export function useBrandLogoUrl(): string | null {
    const { projectAppearance } = usePage().props;
    const { resolvedAppearance } = useAppearance();

    if (resolvedAppearance === 'dark') {
        return (
            projectAppearance?.logoDarkUrl ?? projectAppearance?.logoUrl ?? null
        );
    }

    return projectAppearance?.logoUrl ?? null;
}
