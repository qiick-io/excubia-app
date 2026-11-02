import type { Auth } from '@/types/auth';
import type { SharedProjectSettings } from '@/types/project-settings';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            locale: string;
            availableLocales: Record<string, string>;
            sidebarOpen: boolean;
            projectSettings?: SharedProjectSettings;
            [key: string]: unknown;
        };
    }
}
