import { useState } from 'react';
import { AiAssistantPanel } from '@/components/ai/ai-assistant-panel';
import { AppCommandPalette } from '@/components/app-command-palette';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { ShortcutsProvider } from '@/components/keyboard-shortcuts';
import { useAccessibilityPreferences } from '@/hooks/use-accessibility-preferences';
import { SkipToContent } from '@/components/skip-to-content';
import type { AppLayoutProps } from '@/types';

/**
 * Authenticated app layout with collapsible sidebar and header bar. It holds the keyboard
 * shortcuts and the command palette (#274): the palette's search icon sits right after the
 * sidebar toggle, in the header's `leading` slot, leaving the right side to page actions.
 * @param {AppLayoutProps} props - Layout props from Inertia pages.
 * @param {React.ReactNode} props.children - Page content.
 * @param {AppLayoutProps['breadcrumbs']} [props.breadcrumbs=[]] - Breadcrumb trail for the header.
 * @param {AppLayoutProps['headerActions']} [props.headerActions] - Optional actions slot in the header.
 * @returns {JSX.Element}
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    headerActions,
    hasPageHeading = false,
}: AppLayoutProps) {
    const [isPaletteOpen, setIsPaletteOpen] = useState(false);
    const { singleKeyShortcutsDisabled } = useAccessibilityPreferences();
    const pageTitle = breadcrumbs.at(-1)?.title;

    return (
        <ShortcutsProvider
            canUseSingleKeyShortcuts={!singleKeyShortcutsDisabled}
        >
            <AppShell variant="sidebar">
                <SkipToContent />
                <AppSidebar />
                <AppContent
                    variant="sidebar"
                    id="main-content"
                    className="overflow-hidden"
                    tabIndex={-1}
                >
                    <AppSidebarHeader
                        breadcrumbs={breadcrumbs}
                        leading={
                            <CommandPaletteTrigger
                                onOpen={() => setIsPaletteOpen(true)}
                            />
                        }
                        actions={headerActions}
                    />
                    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                        {!hasPageHeading && pageTitle ? (
                            <h1 className="sr-only">{pageTitle}</h1>
                        ) : null}
                        {children}
                    </div>
                </AppContent>
                <AiAssistantPanel />
                <AppCommandPalette
                    open={isPaletteOpen}
                    onOpenChange={setIsPaletteOpen}
                />
            </AppShell>
        </ShortcutsProvider>
    );
}
