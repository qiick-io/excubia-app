<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * The sync writes this file once and never overwrites or checks it: it is the repo's own.
 *
 * - `namespace`: the root namespace the rules check (`App` for an app, `Qiick\Xyz` for a package).
 * - `ignoring`: per rule, the classes or namespaces it skips. Only framework or package
 *   scaffolding that must keep its upstream name, or a deferred clean-up with its issue.
 *   Give the reason in a comment on each entry. Rule keys: a sub-namespace of the suffix
 *   rules (`Http\Middleware`, `Actions`, `Services`, …), or `strict types`, `env`, `Data`,
 *   `Enums`, `Contracts`, `traits`.
 * - `suffixes`: extra sub-namespace => suffix pairs for roles of this repo (`Parsers` => `Parser`).
 */
return [
    'namespace' => 'App',

    'ignoring' => [
        'strict types' => [
            // Laravel starter-kit files keep their upstream form.
            'App\Actions\Fortify',
            'App\Concerns',
            'App\Http\Controllers\Controller',
            'App\Http\Controllers\Settings',
            'App\Http\Middleware',
            'App\Http\Requests\Settings',
            'App\Models\User',
            'App\Providers',
        ],
        'Http\Middleware' => [
            // Starter-kit middleware, never renamed.
            'App\Http\Middleware\HandleAppearance',
            'App\Http\Middleware\HandleInertiaRequests',
        ],
        'traits' => [
            // Starter-kit validation-rule traits, never renamed.
            'App\Concerns',
        ],
    ],

    'suffixes' => [
        // laravel/ai agents and tools of the assistant.
        'Ai\\Agents' => 'Agent',
        'Ai\\Tools' => 'Tool',
    ],

    // Thin controllers (app-ui v0.2.0): existing debt, a ratchet. Lower an entry when the
    // controller shrinks; never raise it.
    'controllerPublicMethods' => [
        'App\Http\Controllers\ProjectController' => 6,
        'App\Http\Controllers\RoleController' => 6,
        'App\Http\Controllers\UserController' => 6,
    ],
];
