<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SyncPermissionsAction;
use App\Http\Requests\ListQueryRequest;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Read-only permissions reference (`/settings/permissions`) with the roles holding each
 * permission, plus a re-sync from PermissionEnum. Copied from qiick-io/externa-core without
 * its create/update/delete: Excubia's permissions are defined by the enum only.
 */
class PermissionController extends Controller
{
    public function index(ListQueryRequest $request): Response
    {
        Gate::authorize('viewAny', Permission::class);

        return Inertia::render('admin/permissions/index', [
            'permissions' => PermissionResource::collection(Permission::query()
                ->with('roles:id,name')
                ->orderBy('name')
                ->paginate($request->perPage())
                ->withQueryString()),
        ]);
    }

    public function sync(SyncPermissionsAction $syncPermissions): RedirectResponse
    {
        Gate::authorize('sync', Permission::class);

        $syncPermissions->execute();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Permissions synchronized.')]);

        return to_route('permissions.index');
    }
}
