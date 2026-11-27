<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Models\Principal;

/**
 * Excubia's overrides of the qiick/laravel-access defaults (vendor/qiick/laravel-access/config/access.php,
 * which documents every key). Keys left out here keep the package value.
 */
return [

    // `api_key` resolves the owner of a personal API key: an API actor, never a web session.
    'api_guards' => ['api_key'],

    'web_guards' => ['web'],

    'permission_enum' => PermissionEnum::class,

    'principals' => [
        // Excubia's subclass adds the `apiKeys` relation.
        'model' => Principal::class,
        'types' => ['user', 'service'],
        'organisation_required' => false,
        'instance_types' => [],
    ],

    'two_factor' => [
        // Routes needed to enrol a factor (or leave) without a redirect loop.
        'exempt_route_names' => [
            'logout',
            'security.edit',
            'user-password.update',
            'profile.edit',
            'profile.update',
            'password.confirm*',
            'two-factor.*',
            'passkey.*',
        ],
    ],

];
