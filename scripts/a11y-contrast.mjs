#!/usr/bin/env node
/**
 * WCAG 2.2 AA contrast gate for the theme tokens, copied from qiick-io/app-ui
 * `scripts/a11y-contrast.mjs` (externa-core #255) with the theme read from
 * resources/css (where `@qiick/theme` installs it).
 *
 * Reads palette + semantic tokens from resources/css/qiick-theme.css and
 * checks every foreground/background pair below in light, dark,
 * high-contrast and high-contrast dark, for the default brand (teal) and for
 * every installed preset (resources/css/qiick-theme-*.css). A custom `--brand-primary`
 * set by an app is the app's to check.
 *
 * Thresholds: text ≥4.5:1, UI/border/focus ≥3:1.
 *
 * Usage: node scripts/a11y-contrast.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const themeDir = path.join(root, 'resources/css');
const cssPath = path.join(themeDir, 'qiick-theme.css');
const css = fs.readFileSync(cssPath, 'utf8');

/** @type {Record<string, string>} */
const palette = {};

for (const match of css.matchAll(
    /--color-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8})\b/g,
)) {
    palette[`--color-${match[1]}`] = normalizeHex(match[2]);
}

palette['#ffffff'] = '#ffffff';
palette['#000000'] = '#000000';

/**
 * @param {string} block
 * @returns {Record<string, string>}
 */
function parseTokenBlock(block) {
    /** @type {Record<string, string>} */
    const tokens = {};

    for (const match of block.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/g)) {
        tokens[`--${match[1]}`] = match[2].trim();
    }

    return tokens;
}

/**
 * Extract the first CSS ruleset body for `selector`.
 * @param {string} selector
 * @param {string} [source]
 */
function extractBlock(selector, source = css) {
    const needle = `${selector} {`;
    const start = source.indexOf(needle);

    if (start === -1) {
        throw new Error(`Missing ruleset ${selector} in ${cssPath}`);
    }

    let i = start + needle.length;
    let depth = 1;

    while (i < source.length && depth > 0) {
        const ch = source[i];

        if (ch === '{') {
            depth += 1;
        } else if (ch === '}') {
            depth -= 1;
        }

        i += 1;
    }

    return source.slice(start + needle.length, i - 1);
}

const baseLight = parseTokenBlock(extractBlock(':root'));
const baseDark = parseTokenBlock(extractBlock('.dark'));
const highContrastTokens = parseTokenBlock(extractBlock('html.high-contrast'));
const highContrastDarkTokens = parseTokenBlock(
    extractBlock('html.high-contrast.dark'),
);

/** Default brand plus each preset file (brand-only tokens over the base). */
const brands = [
    { name: 'teal (default)', light: {}, dark: {} },
    ...fs
        .readdirSync(themeDir)
        .filter((file) => /^qiick-theme-.+\.css$/.test(file))
        .map((file) => {
            const source = fs.readFileSync(path.join(themeDir, file), 'utf8');

            return {
                name: file.replace(/^qiick-theme-|\.css$/g, ''),
                light: parseTokenBlock(extractBlock(':root', source)),
                dark: parseTokenBlock(extractBlock('.dark', source)),
            };
        }),
];

/**
 * @param {string} hex
 */
function normalizeHex(hex) {
    let value = hex.trim().replace(/^#/, '');

    if (value.length === 3) {
        value = value
            .split('')
            .map((ch) => ch + ch)
            .join('');
    }

    if (value.length === 8) {
        value = value.slice(0, 6);
    }

    return `#${value.toLowerCase()}`;
}

/**
 * Split top-level comma args inside a `var(...)` body (respects nesting).
 * @param {string} body
 * @returns {string[]}
 */
function splitVarArgs(body) {
    /** @type {string[]} */
    const parts = [];
    let depth = 0;
    let current = '';

    for (const ch of body) {
        if (ch === '(') {
            depth += 1;
            current += ch;
            continue;
        }

        if (ch === ')') {
            depth -= 1;
            current += ch;
            continue;
        }

        if (ch === ',' && depth === 0) {
            parts.push(current.trim());
            current = '';
            continue;
        }

        current += ch;
    }

    if (current.trim() !== '') {
        parts.push(current.trim());
    }

    return parts;
}

/**
 * Resolve a token value to a solid hex (default brand; no --brand-primary).
 * @param {string} value
 * @param {Record<string, string>} tokens
 * @param {number} depth
 */
function resolveHex(value, tokens, depth = 0) {
    if (depth > 12) {
        throw new Error(`Token resolution too deep: ${value}`);
    }

    const trimmed = value.replace(/\s+/g, ' ').trim();

    if (/^#[0-9a-fA-F]{3,8}$/.test(trimmed)) {
        return normalizeHex(trimmed);
    }

    // color-mix(in oklab, <color>, <color> N%) — used by --primary-hover (#449).
    const mixMatch = trimmed.match(/^color-mix\(in oklab,(.*)\)$/i);

    if (mixMatch) {
        const [first, second] = splitVarArgs(mixMatch[1]);
        const secondMatch = second.match(/^(.*)\s+([\d.]+)%$/);

        if (!first || !secondMatch) {
            throw new Error(`Unsupported color-mix: ${value}`);
        }

        return mixOklab(
            resolveHex(first, tokens, depth + 1),
            resolveHex(namedColor(secondMatch[1]), tokens, depth + 1),
            Number(secondMatch[2]) / 100,
        );
    }

    // var(--a, var(--b, fallback)) or var(--a, #fff) — may be multi-line in CSS.
    const varMatch = trimmed.match(/^var\((.*)\)$/i);

    if (varMatch) {
        const args = splitVarArgs(varMatch[1]);
        const name = args[0]?.trim();
        const fallback = args.slice(1).join(',').trim();

        if (!name || !name.startsWith('--')) {
            throw new Error(`Invalid var() name in: ${value}`);
        }

        // Prefer palette / defined tokens; skip unresolved brand overrides.
        if (Object.hasOwn(palette, name)) {
            return palette[name];
        }

        if (Object.hasOwn(tokens, name)) {
            try {
                return resolveHex(tokens[name], tokens, depth + 1);
            } catch {
                // fall through to fallback (e.g. unset --brand-primary*)
            }
        }

        if (fallback) {
            return resolveHex(fallback, tokens, depth + 1);
        }

        throw new Error(`Unresolved custom property ${name}`);
    }

    throw new Error(`Cannot resolve color value: ${value}`);
}

/**
 * @param {string} hex
 * @returns {[number, number, number]}
 */
function hexToRgb(hex) {
    const value = normalizeHex(hex).slice(1);

    return [
        Number.parseInt(value.slice(0, 2), 16) / 255,
        Number.parseInt(value.slice(2, 4), 16) / 255,
        Number.parseInt(value.slice(4, 6), 16) / 255,
    ];
}

/**
 * @param {string} value
 */
function namedColor(value) {
    const named = { black: '#000000', white: '#ffffff' };

    return named[value.trim()] ?? value;
}

/**
 * Mix two sRGB colours in OKLab, like CSS `color-mix(in oklab, a, b weight)`.
 * @param {string} firstHex
 * @param {string} secondHex
 * @param {number} secondWeight - 0…1
 */
function mixOklab(firstHex, secondHex, secondWeight) {
    const first = linearToOklab(hexToRgb(firstHex).map(toLinear));
    const second = linearToOklab(hexToRgb(secondHex).map(toLinear));
    const mixed = first.map(
        (channel, index) =>
            channel * (1 - secondWeight) + second[index] * secondWeight,
    );

    return (
        '#' +
        oklabToLinear(mixed)
            .map((channel) => {
                const srgb =
                    channel <= 0.0031308
                        ? channel * 12.92
                        : 1.055 * channel ** (1 / 2.4) - 0.055;

                return Math.round(Math.min(1, Math.max(0, srgb)) * 255)
                    .toString(16)
                    .padStart(2, '0');
            })
            .join('')
    );
}

/**
 * @param {number[]} rgb - linear sRGB
 */
function linearToOklab([r, g, b]) {
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

    return [
        0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
        1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
        0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
    ];
}

/**
 * @param {number[]} lab
 */
function oklabToLinear([lightness, a, b]) {
    const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;

    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ];
}

/**
 * @param {number} channel
 */
function toLinear(channel) {
    return channel <= 0.04045
        ? channel / 12.92
        : ((channel + 0.055) / 1.055) ** 2.4;
}

/**
 * @param {string} hex
 */
function relativeLuminance(hex) {
    const [r, g, b] = hexToRgb(hex);

    return 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);
}

/**
 * @param {string} foreground
 * @param {string} background
 */
function contrastRatio(foreground, background) {
    const l1 = relativeLuminance(foreground);
    const l2 = relativeLuminance(background);
    const lighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * @typedef {{ name: string, fg: string, bg: string, need: number }} Pair
 */

/** @type {Pair[]} */
const colourPairs = [
    {
        name: 'foreground / background',
        fg: '--foreground',
        bg: '--background',
        need: 4.5,
    },
    {
        name: 'foreground / card',
        fg: '--card-foreground',
        bg: '--card',
        need: 4.5,
    },
    {
        name: 'foreground / popover',
        fg: '--popover-foreground',
        bg: '--popover',
        need: 4.5,
    },
    {
        name: 'muted-foreground / background',
        fg: '--muted-foreground',
        bg: '--background',
        need: 4.5,
    },
    {
        name: 'muted-foreground / card',
        fg: '--muted-foreground',
        bg: '--card',
        need: 4.5,
    },
    {
        name: 'muted-foreground / muted',
        fg: '--muted-foreground',
        bg: '--muted',
        need: 4.5,
    },
    {
        name: 'secondary-foreground / secondary',
        fg: '--secondary-foreground',
        bg: '--secondary',
        need: 4.5,
    },
    {
        name: 'accent-foreground / accent',
        fg: '--accent-foreground',
        bg: '--accent',
        need: 4.5,
    },
    {
        name: 'primary-foreground / primary',
        fg: '--primary-foreground',
        bg: '--primary',
        need: 4.5,
    },
    {
        name: 'primary-foreground / primary-hover',
        fg: '--primary-foreground',
        bg: '--primary-hover',
        need: 4.5,
    },
    {
        name: 'destructive-foreground / destructive',
        fg: '--destructive-foreground',
        bg: '--destructive',
        need: 4.5,
    },
    {
        name: 'success-foreground / success',
        fg: '--success-foreground',
        bg: '--success',
        need: 4.5,
    },
    {
        name: 'warning-foreground / warning',
        fg: '--warning-foreground',
        bg: '--warning',
        need: 4.5,
    },
    {
        name: 'info-foreground / info',
        fg: '--info-foreground',
        bg: '--info',
        need: 4.5,
    },
    {
        name: 'ai-foreground / ai',
        fg: '--ai-foreground',
        bg: '--ai',
        need: 4.5,
    },
    { name: 'ai-text / ai-bg', fg: '--ai-text', bg: '--ai-bg', need: 4.5 },
    {
        name: 'input / background (UI)',
        fg: '--input',
        bg: '--background',
        need: 3.0,
    },
    {
        name: 'ring / background (focus)',
        fg: '--ring',
        bg: '--background',
        need: 3.0,
    },
    {
        name: 'sidebar-foreground / sidebar',
        fg: '--sidebar-foreground',
        bg: '--sidebar',
        need: 4.5,
    },
    {
        name: 'sidebar-ring / sidebar (focus)',
        fg: '--sidebar-ring',
        bg: '--sidebar',
        need: 3.0,
    },
    {
        name: 'sidebar-primary / sidebar-accent (active bar)',
        fg: '--sidebar-primary',
        bg: '--sidebar-accent',
        need: 3.0,
    },
    {
        name: 'sidebar-accent-foreground / sidebar-accent (active item)',
        fg: '--sidebar-accent-foreground',
        bg: '--sidebar-accent',
        need: 4.5,
    },
    {
        name: 'sidebar-primary / sidebar (Assistant nav border)',
        fg: '--sidebar-primary',
        bg: '--sidebar',
        need: 3.0,
    },
];

/** @type {Record<string, Record<string, string>>} */
const modes = {};

for (const brand of brands) {
    const light = { ...baseLight, ...brand.light };
    const dark = { ...light, ...baseDark, ...brand.dark };

    // html.high-contrast outranks :root / .dark, so it wins over a preset too.
    modes[`${brand.name} / light`] = light;
    modes[`${brand.name} / dark`] = dark;
    modes[`${brand.name} / high-contrast`] = {
        ...light,
        ...highContrastTokens,
    };
    modes[`${brand.name} / high-contrast-dark`] = {
        ...dark,
        ...highContrastDarkTokens,
    };
}

let failures = 0;

for (const [mode, tokens] of Object.entries(modes)) {
    console.log(`\n[${mode}]`);

    for (const pair of colourPairs) {
        const fgValue = tokens[pair.fg];
        const bgValue = tokens[pair.bg];

        if (!fgValue || !bgValue) {
            console.error(
                `  FAIL  ${pair.name}: missing token ${!fgValue ? pair.fg : pair.bg}`,
            );
            failures += 1;
            continue;
        }

        try {
            const fgHex = resolveHex(fgValue, tokens);
            const bgHex = resolveHex(bgValue, tokens);
            const ratio = contrastRatio(fgHex, bgHex);
            const ok = ratio + 1e-9 >= pair.need;
            const line = `  ${ok ? 'PASS' : 'FAIL'}  ${pair.name}: ${ratio.toFixed(2)}:1 (need ${pair.need}:1) [${fgHex} on ${bgHex}]`;

            if (ok) {
                console.log(line);
            } else {
                console.error(line);
                failures += 1;
            }
        } catch (error) {
            console.error(
                `  FAIL  ${pair.name}: ${error instanceof Error ? error.message : error}`,
            );
            failures += 1;
        }
    }
}

if (failures > 0) {
    console.error(`\na11y contrast: ${failures} pair(s) below WCAG 2.2 AA.`);
    process.exit(1);
}

console.log('\na11y contrast: all pairs pass WCAG 2.2 AA.');
