<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\GlobalSearchResultData;
use App\Http\Requests\GlobalSearchRequest;
use App\Models\User;
use App\Services\GlobalSearchService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * The command palette's server search (#274): records by name, grouped by type, only those
 * the user may open.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, #[CurrentUser] User $user, GlobalSearchService $search): JsonResponse
    {
        $groups = [];

        foreach ($search->search($user, $request->searchQuery()) as $type => $results) {
            $groups[] = [
                'type' => $type,
                'items' => array_map(fn (GlobalSearchResultData $result): array => $result->toArray(), $results),
            ];
        }

        return response()->json(['groups' => $groups]);
    }
}
