<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails closed while the MCP server (#234) is off (`EXCUBIA_MCP_ENABLED`, false by default): the
 * endpoint answers 404 before any key is looked up, as externa-core's middleware of this name.
 */
class EnsureMcpEnabledMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('excubia.mcp_enabled'), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
