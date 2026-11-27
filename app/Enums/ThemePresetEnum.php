<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Built-in project appearance theme presets (config/theme-presets.php), as in qiick-io/externa-core.
 */
enum ThemePresetEnum: string
{
    case Genziana = 'genziana';
    case Indaco = 'indaco';
    case Ametista = 'ametista';
    case Robbia = 'robbia';
    case Ardesia = 'ardesia';

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
        return self::Genziana;
    }

    public static function tryFromNullable(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }
}
