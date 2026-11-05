import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { useBrandLogoUrl } from '@/hooks/use-brand-logo-url';

/**
 * Brand mark and application name for sidebar and header chrome. An uploaded logo renders as a
 * plain image, as in externa-core; otherwise the default mark.
 * @returns {JSX.Element}
 */
export default function AppLogo() {
    const { name } = usePage().props;
    const logoUrl = useBrandLogoUrl();

    return (
        <>
            {logoUrl ? (
                <img
                    src={logoUrl}
                    alt={name}
                    className="h-8 w-auto max-w-24 object-contain"
                />
            ) : (
                <div className="flex aspect-square size-8 items-center justify-center">
                    <AppLogoIcon className="size-7" />
                </div>
            )}
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {name}
                </span>
            </div>
        </>
    );
}
