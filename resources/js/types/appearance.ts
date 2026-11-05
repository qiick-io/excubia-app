import type { DefaultAppearance } from '@/types/project-settings';

export type ThemePresetSlug =
    | 'teal'
    | 'blue'
    | 'indigo'
    | 'violet'
    | 'rose'
    | 'slate';

/** One preset offered by the Appearance picker (ThemePresetService::catalog()). */
export type ThemePresetCatalogItem = {
    slug: ThemePresetSlug;
    name: string;
    aiOverride: boolean;
    swatchLight: string;
    swatchDark: string;
    /** Base surfaces merged with the preset's brand colours (server-resolved). */
    light: Record<string, string>;
    dark: Record<string, string>;
};

/** Resolved theme CSS variables, as rendered in the app.blade.php `<style>` block. */
export type ThemeCssPayload = {
    light: Record<string, string>;
    dark: Record<string, string>;
    brandPrimary: string | null;
    brandPrimaryDark: string | null;
    brandPrimaryForeground: string | null;
    brandPrimaryDarkForeground: string | null;
};

/** Saved theme shared with every page (HandleInertiaRequests `projectAppearance`). */
export type ProjectAppearance = {
    themePreset: ThemePresetSlug;
    themeCss: ThemeCssPayload;
};

/** Appearance settings form payload (AppearanceSettingsController::edit()). */
export type AppearanceSettings = {
    theme_preset: ThemePresetSlug;
    project_color: string | null;
    project_color_dark: string | null;
    default_appearance: DefaultAppearance;
    theme_presets: ThemePresetCatalogItem[];
    brand_passes_aa: boolean;
};
