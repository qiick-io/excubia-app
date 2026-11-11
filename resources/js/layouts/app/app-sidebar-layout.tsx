import { useState } from 'react';
import { AiAssistantPanel } from '@/components/ai/ai-assistant-panel';
import { AppCommandPalette } from '@/components/app-command-palette';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CommandPaletteTrigger } from '@/components/command-palette';
import { ShortcutsProvider } from '@/components/keyboard-shortcuts';
import { SkipToContent } from '@/components/skip-to-content';
import type { AppLayoutProps } from '@/types';

/**
 * Authenticated app layout with collapsible sidebar and header bar. It holds the keyboard
 * shortcuts and the command palette (#274): the palette's trigger is the first header action.
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
}: AppLayoutProps) {
    const [isPaletteOpen, setIsPaletteOpen] = useState(false);

    return (
        <ShortcutsProvider>
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
                        actions={
                            <>
                                <CommandPaletteTrigger
                                    onOpen={() => setIsPaletteOpen(true)}
                                />
                                {headerActions}
                            </>
                        }
                    />
                    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
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
