<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Concerns;

use App\Services\SettingsRepositoryService;
use Illuminate\Http\Request;

/**
 * Shared activity-log writer for project settings section updates (as in qiick-io/externa-core).
 * Only the saved keys are logged, never their values, so secrets such as the OIDC client secret
 * never reach the log: a flag says whether a secret was replaced.
 */
trait LogsProjectSettingsUpdateTrait
{
    /**
     * @param  array<string, mixed>  $values  the values just saved
     */
    protected function logProjectSettingsUpdate(Request $request, string $section, array $values): void
    {
        $updatedSecretKeys = array_filter(
            SettingsRepositoryService::SECRET_KEYS,
            fn (string $key): bool => is_string($values[$key] ?? null) && $values[$key] !== '',
        );

        activity()
            ->causedBy($request->user())
            ->useLog('settings')
            ->event('settings_updated')
            ->withProperties([
                'section' => $section,
                'keys' => array_keys($values),
                'secret_keys_updated' => $updatedSecretKeys !== [],
            ])
            ->log('Project settings updated');
    }
}
