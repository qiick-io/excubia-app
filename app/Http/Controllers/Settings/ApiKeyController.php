<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
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
 * Settings → API keys (#315), as externa-core's API keys page but personal: the signed-in user
 * lists, creates and revokes their own keys. A new key is flashed once (`apiKey`) and never
 * shown again.
 */
class ApiKeyController extends Controller
{
    public function index(ListQueryRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

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
        $user = $request->user();

        Inertia::flash('apiKey', $createApiKey->execute($user, $request->apiKeyData()));

        return to_route('api-keys.index');
    }

    public function destroy(ApiKey $apiKey, RevokeApiKeyAction $revokeApiKey): RedirectResponse
    {
        Gate::authorize('delete', $apiKey);

        $revokeApiKey->execute($apiKey);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('api-keys.index');
    }
}
