import { FlashToasts } from '@/components/flash-toasts';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

/**
 * App shell layout used by Inertia pages via `@/layouts/app-layout`.
 * @param {AppLayoutProps} props - Layout props passed through to the sidebar template.
 * @returns {JSX.Element}
 */
export default function AppLayout({
    children,
    breadcrumbs,
    headerActions,
    ...props
}: AppLayoutProps) {
    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            headerActions={headerActions}
            {...props}
        >
            <FlashToasts />
            {children}
        </AppLayoutTemplate>
    );
}
