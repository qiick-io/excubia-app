<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/settings/system` (#117): the health of this Excubia install, behind `can-show-system`.
 */
class SystemHealthController extends Controller
{
    public function index(SystemHealthService $systemHealth): Response
    {
        Gate::authorize('viewAny', SystemHealthService::class);

        return Inertia::render('admin/system/index', $systemHealth->snapshot());
    }
}
