import { createInertiaApp } from '@inertiajs/react';
import { AppErrorBoundary } from '@/components/app-error-boundary';
import { FlashToasts } from '@/components/flash-toasts';
import { FocusAfterNavigation } from '@/components/focus-after-navigation';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeAccessibilityPreferences } from '@/hooks/use-accessibility-preferences';
import { initializeTheme } from '@/hooks/use-appearance';
import { initI18n } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Pages wrap themselves in AppLayout / AuthLayout, as in externa-core.
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    strictMode: true,
    withApp(app, { page }) {
        const locale =
            typeof page.props.locale === 'string' ? page.props.locale : 'en';
        initI18n(locale);

        return (
            <AppErrorBoundary>
                <TooltipProvider delayDuration={0}>
                    <FocusAfterNavigation />
                    {/* At the root so auth and error pages show flashed toasts too (#423). */}
                    <FlashToasts />
                    {app}
                    {/* bottom-right: top-right toast was intercepting header Save/Create clicks */}
                    <Toaster
                        position="bottom-right"
                        duration={5000}
                        closeButton
                    />
                </TooltipProvider>
            </AppErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
initializeAccessibilityPreferences();
