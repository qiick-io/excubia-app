<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a push from excubia-core by its project token (`Authorization: Bearer`), never
 * by a user session. The project is stored in the request attribute `project`.
 * Answers 401 for a missing, unknown or revoked token, 403 for a disabled project and 413 for a
 * body above `excubia.push_max_kilobytes`.
 */
class AuthenticateProjectTokenMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $project = $token === null
            ? null
            : Project::query()->where('push_token_hash', hash('sha256', $token))->first();

        if ($project === null) {
            return response()->json(['message' => 'Missing, unknown or revoked project token.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $project->is_enabled) {
            return response()->json(['message' => 'This project is disabled in Excubia.'], Response::HTTP_FORBIDDEN);
        }

        if (strlen($request->getContent()) > config('excubia.push_max_kilobytes') * 1024) {
            return response()->json(['message' => 'The body is larger than '.config('excubia.push_max_kilobytes').' KB.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $request->attributes->set('project', $project);

        return $next($request);
    }
}
