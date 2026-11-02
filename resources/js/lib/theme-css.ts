import { contrastingForeground } from '@/lib/contrasting-foreground';
import { themePresetBySlug } from '@/lib/theme-presets';
import type { ThemePresetSlug, ThemePresetTokens } from '@/lib/theme-presets';
import type { ThemeCssPayload } from '@/types/appearance';

const BRAND_LINKED_KEYS = [
    'primary',
    'primary-foreground',
    'ring',
    'chart-1',
    'sidebar-primary',
    'sidebar-primary-foreground',
    'sidebar-ring',
] as const;

/** Id of the theme `<style>` element rendered by `app.blade.php`. */
export const THEME_STYLE_ELEMENT_ID = 'excubia-theme-css';

/** Rejects values that could close the declaration block (`;`, `{`, `}`, `<`). */
const SAFE_CSS_VALUE = /^[^;{}<>]+$/;

/**
 * Apply resolved theme CSS variables to the document for live preview.
 *
 * Rewrites the same `<style id="externa-theme-css">` the server renders, as a
 * `:root { … }` light block and a `.dark { … }` block. Nothing is set inline on
 * `<html>`, so the `.dark` (and `html.high-contrast`) rules keep winning in
 * dark mode. Side effect: creates the style element in `<head>` if missing.
 */
export function applyThemeCss(themeCss: ThemeCssPayload): void {
    let styleElement = document.getElementById(
        THEME_STYLE_ELEMENT_ID,
    ) as HTMLStyleElement | null;

    if (!styleElement) {
        styleElement = document.createElement('style');
        styleElement.id = THEME_STYLE_ELEMENT_ID;
        document.head.appendChild(styleElement);
    }

    const lightDeclarations: Record<string, string> = { ...themeCss.light };

    if (themeCss.brandPrimary) {
        const brandPrimaryDark =
            themeCss.brandPrimaryDark ?? themeCss.brandPrimary;

        lightDeclarations['brand-primary'] = themeCss.brandPrimary;
        lightDeclarations['brand-primary-foreground'] =
            themeCss.brandPrimaryForeground ??
            contrastingForeground(themeCss.brandPrimary) ??
            '#ffffff';
        lightDeclarations['brand-primary-dark'] = brandPrimaryDark;
        lightDeclarations['brand-primary-dark-foreground'] =
            themeCss.brandPrimaryDarkForeground ??
            contrastingForeground(brandPrimaryDark) ??
            '#ffffff';
    }

    styleElement.textContent = [
        `:root { ${cssDeclarations(lightDeclarations)} }`,
        `.dark { ${cssDeclarations(themeCss.dark)} }`,
    ].join('\n');
}

function cssDeclarations(tokens: ThemePresetTokens): string {
    return Object.entries(tokens)
        .filter(([, value]) => SAFE_CSS_VALUE.test(value))
        .map(([key, value]) => `--${key}: ${value};`)
        .join(' ');
}

/**
 * Build a ThemeCssPayload client-side for appearance form live preview.
 */
export function buildPreviewThemeCss(
    themePreset: ThemePresetSlug,
    projectColor: string | null,
    projectColorDark: string | null,
): ThemeCssPayload {
    const preset = themePresetBySlug(themePreset);
    let light: ThemePresetTokens = { ...preset.light };
    let dark: ThemePresetTokens = { ...preset.dark };

    const hasCustomLight = Boolean(projectColor);
    const hasCustomDark = Boolean(projectColorDark);
    const effectiveDark = hasCustomDark
        ? projectColorDark
        : hasCustomLight
          ? projectColor
          : null;

    let brandPrimaryForeground: string | null = null;
    let brandPrimaryDarkForeground: string | null = null;

    if (hasCustomLight && projectColor) {
        brandPrimaryForeground = contrastingForeground(projectColor);
        light = stripBrandLinkedKeys(light);
    }

    if (effectiveDark && (hasCustomLight || hasCustomDark)) {
        brandPrimaryDarkForeground = contrastingForeground(effectiveDark);
        dark = stripBrandLinkedKeys(dark);
    }

    return {
        light,
        dark,
        brandPrimary: hasCustomLight ? projectColor : null,
        brandPrimaryDark:
            hasCustomLight || hasCustomDark ? effectiveDark : null,
        brandPrimaryForeground,
        brandPrimaryDarkForeground,
    };
}

function stripBrandLinkedKeys(tokens: ThemePresetTokens): ThemePresetTokens {
    const next = { ...tokens };

    for (const key of BRAND_LINKED_KEYS) {
        delete next[key];
    }

    return next;
}
