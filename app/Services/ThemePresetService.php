<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ThemePresetEnum;

/**
 * Resolves built-in theme presets (config/theme-presets.php) into CSS variables for the
 * document shell, as in qiick-io/externa-core. Precedence: custom brand hex → selected preset
 * → default teal. A custom hex removes the brand-linked preset keys so `--brand-primary`
 * (resources/css/app.css) wins, and gets an AA-checked foreground.
 *
 * ponytail: externa's AI-accent override (OKLCH hue check) is left out because Excubia has no
 * AI surfaces; port OklchHue and the `ai_override*` config if one is added.
 *
 * @phpstan-type ThemeCssBag array{
 *     light: array<string, string>,
 *     dark: array<string, string>,
 *     brandPrimary: string|null,
 *     brandPrimaryDark: string|null,
 *     brandPrimaryForeground: string|null,
 *     brandPrimaryDarkForeground: string|null,
 *     brandPassesAa: bool,
 *     themePreset: string
 * }
 */
class ThemePresetService
{
    public const FOREGROUND_LIGHT = '#FFFFFF';

    public const FOREGROUND_DARK = '#0E171E';

    private const TEXT_CONTRAST_MIN = 4.5;

    private const UI_CONTRAST_MIN = 3.0;

    /**
     * Contrast-safe focus rings used when the brand colour is below 3:1
     * on the page background (externa-core#453). Mirrors the `--ring` defaults in app.css.
     */
    private const FALLBACK_RING_LIGHT = 'var(--color-teal-600)';

    private const FALLBACK_RING_DARK = 'var(--color-teal-400)';

    /**
     * Brand-linked keys omitted from preset injection when a custom hex is set.
     *
     * @var list<string>
     */
    private const BRAND_LINKED_KEYS = [
        'primary',
        'primary-foreground',
        'ring',
        'chart-1',
        'sidebar-primary',
        'sidebar-primary-foreground',
        'sidebar-ring',
    ];

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    /**
     * The saved preset and brand colours, resolved (for app.blade.php and the shared props).
     *
     * @return ThemeCssBag
     */
    public function current(): array
    {
        return $this->resolve($this->settings->themePreset(), $this->settings->projectColor(), $this->settings->projectColorDark());
    }

    /**
     * Presets for the Appearance picker.
     *
     * @return list<array{slug: string, name: string, swatchLight: string, swatchDark: string}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (ThemePresetEnum::cases() as $case) {
            $definition = $this->presetDefinition($case);

            if ($definition === []) {
                continue;
            }

            $light = is_array($definition['light'] ?? null) ? $definition['light'] : [];
            $dark = is_array($definition['dark'] ?? null) ? $definition['dark'] : [];

            $catalog[] = [
                'slug' => $case->value,
                'name' => is_string($definition['name'] ?? null) ? $definition['name'] : $case->value,
                'swatchLight' => is_string($light['primary'] ?? null) ? $light['primary'] : '#0E7C86',
                'swatchDark' => is_string($dark['primary'] ?? null) ? $dark['primary'] : '#2CB1BC',
            ];
        }

        return $catalog;
    }

    /**
     * Effective CSS variable maps for the document shell. An unset dark brand colour falls back
     * to the light one.
     *
     * @return ThemeCssBag
     */
    public function resolve(?string $themePresetSlug, ?string $projectColor, ?string $projectColorDark): array
    {
        $preset = ThemePresetEnum::tryFromNullable($themePresetSlug);
        $definition = $this->presetDefinition($preset);

        $light = $this->tokenMap(is_array($definition['light'] ?? null) ? $definition['light'] : []);
        $dark = $this->tokenMap(is_array($definition['dark'] ?? null) ? $definition['dark'] : []);

        $hasCustomLight = is_string($projectColor) && $projectColor !== '';
        $hasCustomDark = is_string($projectColorDark) && $projectColorDark !== '';
        $effectiveDark = $hasCustomDark ? $projectColorDark : ($hasCustomLight ? $projectColor : null);

        $brandAaChecks = [];
        $brandPrimaryForeground = null;
        $brandPrimaryDarkForeground = null;

        if ($hasCustomLight) {
            [$brandPrimaryForeground, $brandAaChecks[]] = $this->deriveBrand($projectColor, $light['background'] ?? self::FOREGROUND_LIGHT);
            $light = [
                ...$this->stripBrandLinkedKeys($light),
                ...$this->ringTokens($projectColor, $light['background'] ?? self::FOREGROUND_LIGHT, self::FALLBACK_RING_LIGHT),
            ];
        }

        if ($effectiveDark !== null) {
            [$brandPrimaryDarkForeground, $brandAaChecks[]] = $this->deriveBrand($effectiveDark, $dark['background'] ?? self::FOREGROUND_DARK);
            $dark = [
                ...$this->stripBrandLinkedKeys($dark),
                ...$this->ringTokens($effectiveDark, $dark['background'] ?? self::FOREGROUND_DARK, self::FALLBACK_RING_DARK),
            ];
        }

        return [
            'light' => $light,
            'dark' => $dark,
            'brandPrimary' => $hasCustomLight ? $projectColor : null,
            'brandPrimaryDark' => $effectiveDark,
            'brandPrimaryForeground' => $brandPrimaryForeground,
            'brandPrimaryDarkForeground' => $brandPrimaryDarkForeground,
            'brandPassesAa' => ! in_array(false, $brandAaChecks, true),
            'themePreset' => $preset->value,
        ];
    }

    /**
     * Foreground for text on the brand colour, and whether the colour passes WCAG AA
     * (4.5:1 text, 3:1 focus ring against the surface).
     *
     * @return array{0: string|null, 1: bool}
     */
    private function deriveBrand(string $hex, string $surfaceBackground): array
    {
        if ($this->parseHex($hex) === null) {
            return [null, false];
        }

        $foreground = $this->pickForeground($hex);

        return [
            $foreground,
            $this->contrastRatio($hex, $foreground) >= self::TEXT_CONTRAST_MIN
                && $this->contrastRatio($this->deriveRing($hex, $surfaceBackground), $surfaceBackground) >= self::UI_CONTRAST_MIN,
        ];
    }

    /**
     * White or ink-950, whichever reads better on the brand colour.
     */
    private function pickForeground(string $hex): string
    {
        $contrastWhite = $this->contrastRatio($hex, self::FOREGROUND_LIGHT);
        $contrastInk = $this->contrastRatio($hex, self::FOREGROUND_DARK);
        $isWhitePassing = $contrastWhite >= self::TEXT_CONTRAST_MIN;
        $isInkPassing = $contrastInk >= self::TEXT_CONTRAST_MIN;

        if ($isWhitePassing !== $isInkPassing) {
            return $isWhitePassing ? self::FOREGROUND_LIGHT : self::FOREGROUND_DARK;
        }

        return $contrastWhite >= $contrastInk ? self::FOREGROUND_LIGHT : self::FOREGROUND_DARK;
    }

    /**
     * Focus ring tokens for a custom brand: the brand itself when it keeps 3:1
     * on the page background (WCAG 2.4.7 / 1.4.11), otherwise the teal fallback.
     *
     * Always emitted (never omitted) so a passing light ring cannot leak into
     * `.dark` through the inline `:root` block.
     *
     * @return array{ring: string, sidebar-ring: string}
     */
    private function ringTokens(string $brandHex, string $background, string $fallbackRing): array
    {
        $ring = $this->contrastRatio($brandHex, $background) >= self::UI_CONTRAST_MIN ? $brandHex : $fallbackRing;

        return ['ring' => $ring, 'sidebar-ring' => $ring];
    }

    /**
     * The brand colour, darkened or lightened when it is too faint against the surface.
     */
    private function deriveRing(string $hex, string $surfaceBackground): string
    {
        if ($this->contrastRatio($hex, $surfaceBackground) >= self::UI_CONTRAST_MIN) {
            return $hex;
        }

        $rgb = $this->parseHex($hex);

        if ($rgb === null) {
            return $hex;
        }

        $factor = ($this->relativeLuminance($surfaceBackground) ?? 1.0) > 0.5 ? 0.72 : 1.35;
        $scaled = array_map(static fn (float $channel): int => (int) max(0, min(255, round($channel * 255 * $factor))), $rgb);
        $candidate = sprintf('#%02X%02X%02X', $scaled[0], $scaled[1], $scaled[2]);

        return $this->contrastRatio($candidate, $surfaceBackground) >= self::UI_CONTRAST_MIN ? $candidate : $hex;
    }

    private function contrastRatio(string $backgroundHex, string $foregroundHex): float
    {
        $first = $this->relativeLuminance($backgroundHex);
        $second = $this->relativeLuminance($foregroundHex);

        if ($first === null || $second === null) {
            return 0.0;
        }

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private function relativeLuminance(string $hex): ?float
    {
        $rgb = $this->parseHex($hex);

        if ($rgb === null) {
            return null;
        }

        $linear = array_map(static fn (float $channel): float => $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4, $rgb);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null Channels in 0–1.
     */
    private function parseHex(string $hex): ?array
    {
        $value = ltrim(trim($hex), '#');

        if (strlen($value) === 3 && ctype_xdigit($value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        if (strlen($value) !== 6 || ! ctype_xdigit($value)) {
            return null;
        }

        return [
            hexdec(substr($value, 0, 2)) / 255,
            hexdec(substr($value, 2, 2)) / 255,
            hexdec(substr($value, 4, 2)) / 255,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presetDefinition(ThemePresetEnum $preset): array
    {
        $definition = config('theme-presets.presets.'.$preset->value);

        return is_array($definition) ? $definition : [];
    }

    /**
     * @param  array<mixed>  $tokens
     * @return array<string, string>
     */
    private function tokenMap(array $tokens): array
    {
        $map = [];

        foreach ($tokens as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    private function stripBrandLinkedKeys(array $tokens): array
    {
        return array_diff_key($tokens, array_flip(self::BRAND_LINKED_KEYS));
    }
}
