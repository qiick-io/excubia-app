<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\Settings\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeySecretOnceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → API keys (#315, #351), personal: the signed-in user (web session) lists, creates,
 * rotates and revokes their own keys. A new or rotated secret is handed once through
 * {@see ApiKeySecretOnceService} (encrypted session payload, never plaintext) and never
 * shown again. Unreachable over the `api_key` guard.
 */
class ApiKeyController extends Controller
{
    public function index(ListQueryRequest $request, ApiKeySecretOnceService $apiKeySecretOnce): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        $response = Inertia::render('settings/api-keys', [
            'apiKeys' => ApiKeyResource::collection($user->apiKeys()
                ->latest('id')
                ->paginate($request->perPage())
                ->withQueryString()),
            'scopes' => array_map(
                fn (ApiKeyScopeEnum $scope): string => $scope->value,
                ApiKeyScopeEnum::scopesCreatorMayGrant($user),
            ),
            'issuedApiKey' => $apiKeySecretOnce->pull($request->session()),
        ])->toResponse($request);

        // Inertia may set Cache-Control for XHR; force no-store so the secret page is not cached.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function store(
        StoreApiKeyRequest $request,
        CreateApiKeyAction $createApiKey,
        ApiKeySecretOnceService $apiKeySecretOnce,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user('web');
        $apiKeyData = $request->apiKeyData();
        $plainSecret = $createApiKey->execute($user, $apiKeyData, $user);

        $apiKeySecretOnce->store(
            $request->session(),
            $plainSecret,
            $apiKeyData->name,
            'created',
        );

        return to_route('api-keys.index');
    }

    public function rotate(
        ApiKey $apiKey,
        RotateApiKeyAction $rotateApiKey,
        ApiKeySecretOnceService $apiKeySecretOnce,
    ): RedirectResponse {
        Gate::authorize('update', $apiKey);

        /** @var User $user */
        $user = request()->user('web');
        $plainSecret = $rotateApiKey->execute($apiKey, $user);

        $apiKeySecretOnce->store(
            request()->session(),
            $plainSecret,
            $apiKey->name,
            'rotated',
            $apiKey->fresh()?->previous_key_valid_until?->toIso8601String(),
        );

        return to_route('api-keys.index');
    }

    public function destroy(ApiKey $apiKey, RevokeApiKeyAction $revokeApiKey): RedirectResponse
    {
        Gate::authorize('delete', $apiKey);

        /** @var User $user */
        $user = request()->user('web');

        $revokeApiKey->execute($apiKey, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('api-keys.index');
    }
}
