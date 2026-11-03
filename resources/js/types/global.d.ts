import type { ProjectAppearance } from '@/types/appearance';
import type { Auth } from '@/types/auth';
import type { SharedProjectSettings } from '@/types/project-settings';
import type { PushTokenFlash } from '@/types/projects';
import type { FlashToast } from '@/types/ui';

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
            projectSettings: SharedProjectSettings;
            projectAppearance: ProjectAppearance;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            pushToken?: PushTokenFlash;
        };
    }
}
