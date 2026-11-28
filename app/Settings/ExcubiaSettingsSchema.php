<?php

declare(strict_types=1);

namespace App\Settings;

use App\Services\SettingsRepositoryService;
use Qiick\Settings\Definition;
use Qiick\Settings\SettingsSchema;

/**
 * Every Excubia setting declared for qiick/laravel-settings: instance scope, group `app`, the
 * type of SettingsRepositoryService::TYPES, and SettingsRepositoryService::defaults() as the
 * default. The three SECRET_KEYS are encrypted strings without a default.
 *
 * Defaults are closures because several read config or the environment at the time of the read.
 */
final class ExcubiaSettingsSchema implements SettingsSchema
{
    /**
     * @return list<Definition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (SettingsRepositoryService::TYPES as $key => $type) {
            if (in_array($key, SettingsRepositoryService::SECRET_KEYS, true)) {
                $definitions[] = Definition::string($key)->in(SettingsRepositoryService::GROUP_APP)->secret();

                continue;
            }

            $definition = match ($type) {
                'bool' => Definition::bool($key),
                'int' => Definition::int($key),
                'json' => Definition::json($key),
                'string' => Definition::string($key),
            };

            $definitions[] = $definition
                ->in(SettingsRepositoryService::GROUP_APP)
                ->default(fn (): mixed => SettingsRepositoryService::coerce($type, app(SettingsRepositoryService::class)->defaults()[$key]));
        }

        return $definitions;
    }
}
