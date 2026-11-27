import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

/** Props for authenticated app layouts (header or sidebar variant). */
export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    headerActions?: ReactNode;
    /** The page already renders a visible primary heading. */
    hasPageHeading?: boolean;
};

/** Toast flashed by the server with `Inertia::flash('toast', ...)`. */
export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/** Props for guest/auth marketing-style layouts. */
export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
