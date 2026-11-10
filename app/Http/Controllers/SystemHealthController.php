<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/settings/system` (#117): the health of this Excubia install, behind `can-show-system`, with
 * per-project rows limited to the projects the viewer may see (#152).
 */
class SystemHealthController extends Controller
{
    public function index(SystemHealthService $systemHealth, #[CurrentUser] User $viewer): Response
    {
        Gate::authorize('viewAny', SystemHealthService::class);

        return Inertia::render('admin/system/index', $systemHealth->snapshot($viewer));
    }
}
