import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

/**
 * Brand mark and application name for sidebar and header chrome.
 * @returns {JSX.Element}
 */
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center">
                <AppLogoIcon className="size-7" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {name}
                </span>
            </div>
        </>
    );
}
