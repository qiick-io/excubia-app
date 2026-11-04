<?php

declare(strict_types=1);

/**
 * Codice Perfetto guardrails (see CLAUDE.md).
 *
 * Laravel and starter-kit files keep their upstream names and are excluded with ignoring().
 */
arch('every application class declares strict types')
    ->expect('App')
    ->toUseStrictTypes()
    ->ignoring([
        'App\Actions\Fortify',
        'App\Concerns',
        'App\Http\Controllers\Controller',
        'App\Http\Controllers\Settings',
        'App\Http\Middleware',
        'App\Http\Requests\Settings',
        'App\Models\User',
        'App\Providers',
    ]);

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('env helper is not used outside config')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('Config');

arch('controllers do not use the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('models use bare nouns')
    ->expect('App\Models')
    ->classes()
    ->not->toHaveSuffix('Model');

arch('data objects are readonly and suffixed')
    ->expect('App\Data')
    ->classes()
    ->toBeReadonly()
    ->toHaveSuffix('Data');

arch('actions end with Action')
    ->expect('App\Actions')
    ->classes()
    ->toHaveSuffix('Action')
    ->ignoring('App\Actions\Fortify');

arch('services end with Service')
    ->expect('App\Services')
    ->classes()
    ->toHaveSuffix('Service');

arch('jobs end with Job')
    ->expect('App\Jobs')
    ->classes()
    ->toHaveSuffix('Job');

arch('console commands end with Command')
    ->expect('App\Console\Commands')
    ->classes()
    ->toHaveSuffix('Command');

arch('enums end with Enum')
    ->expect('App\Enums')
    ->enums()
    ->toHaveSuffix('Enum');

arch('controllers end with Controller')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toHaveSuffix('Controller');

arch('form requests end with Request')
    ->expect('App\Http\Requests')
    ->classes()
    ->toHaveSuffix('Request');

arch('policies end with Policy')
    ->expect('App\Policies')
    ->classes()
    ->toHaveSuffix('Policy');

arch('AI agents end with Agent')
    ->expect('App\Ai\Agents')
    ->classes()
    ->toHaveSuffix('Agent');

arch('AI tools end with Tool')
    ->expect('App\Ai\Tools')
    ->classes()
    ->toHaveSuffix('Tool');
