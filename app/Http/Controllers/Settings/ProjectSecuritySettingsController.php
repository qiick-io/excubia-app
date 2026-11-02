<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProjectSecuritySettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Authentication & security project settings: password policy, login attempts, required 2FA
 * and SSO (OIDC). The client secret is write-only. The routes require `settings.manage`.
 */
class ProjectSecuritySettingsController extends Controller
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/project/security', [
            'project' => $this->settings->forEditOnly([
                'password_policy',
                'login_max_attempts',
                'two_factor_required',
                'oidc_enabled',
                'oidc_issuer',
                'oidc_client_id',
                'has_oidc_client_secret',
                'oidc_button_label',
                'oidc_jit_provisioning',
            ]),
            'passwordPolicies' => SettingsRepositoryService::PASSWORD_POLICIES,
            'oidcCallbackUrl' => route('auth.oidc.callback'),
        ]);
    }

    public function update(UpdateProjectSecuritySettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('project.security.edit');
    }
}
