<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in project appearance theme presets (config/theme-presets.php), as in qiick-io/externa-core.
 */
enum ThemePresetEnum: string
{
    case Teal = 'teal';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Rose = 'rose';
    case Slate = 'slate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Excubia's brand preset (config/theme-presets.php `default`).
     */
    public static function default(): self
    {
        return self::Blue;
    }

    public static function tryFromNullable(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }
}
