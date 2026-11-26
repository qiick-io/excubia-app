import type { ProjectAppearance } from '@/types/appearance';
import type { Auth } from '@/types/auth';
import type { SharedProjectSettings } from '@/types/project-settings';
import type { PushTokenFlash, SidebarProjects } from '@/types/projects';
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
            appVersion: string;
            /** Build ID (short commit, linked, or a build label); null without one (#425). */
            appBuild: { id: string; url: string | null } | null;
            isAssistantEnabled: boolean;
            projectSettings: SharedProjectSettings;
            projectAppearance: ProjectAppearance;
            /** Pinned and needs-attention projects under "Projects" in the sidebar (#273). */
            sidebarProjects: SidebarProjects;
            /** The bell's unread badge (#296), read on every visit. */
            notifications: { unread_count: number };
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            pushToken?: PushTokenFlash;
            /** One-time API key secret; Inertia strips flash from history.state. */
            issuedApiKey?: {
                secret: string;
                name: string;
                reason: 'created' | 'rotated';
                previous_key_valid_until: string | null;
            };
            /** One-time alert webhook signing secret; Inertia strips flash from history.state. */
            issuedWebhookSecret?: {
                secret: string;
                reason: 'created' | 'rotated';
            };
        };
    }
}
