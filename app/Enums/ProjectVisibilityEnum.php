<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who sees a project (#70): everyone with `projects.view`, or only admins and super-admins.
 */
enum ProjectVisibilityEnum: string
{
    case Everyone = 'everyone';
    case Admins = 'admins';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
