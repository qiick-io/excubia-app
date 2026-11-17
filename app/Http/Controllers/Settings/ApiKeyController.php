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
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → API keys (#315, #351), personal: the signed-in user (web session) lists, creates,
 * rotates and revokes their own keys. A new or rotated secret is flashed once (`apiKey`) and never
 * shown again. Unreachable over the `api_key` guard.
 */
class ApiKeyController extends Controller
{
    public function index(ListQueryRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('settings/api-keys', [
            'apiKeys' => ApiKeyResource::collection($user->apiKeys()
                ->latest('id')
                ->paginate($request->perPage())
                ->withQueryString()),
            'scopes' => ApiKeyScopeEnum::values(),
        ]);
    }

    public function store(StoreApiKeyRequest $request, CreateApiKeyAction $createApiKey): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');

        Inertia::flash('apiKey', $createApiKey->execute($user, $request->apiKeyData(), $user));

        return to_route('api-keys.index');
    }

    public function rotate(ApiKey $apiKey, RotateApiKeyAction $rotateApiKey): RedirectResponse
    {
        Gate::authorize('update', $apiKey);

        /** @var User $user */
        $user = request()->user('web');

        Inertia::flash('apiKey', $rotateApiKey->execute($apiKey, $user));

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
