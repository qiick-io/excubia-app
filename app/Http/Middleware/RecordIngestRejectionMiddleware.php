<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordIngestRejectionAction;
use App\Enums\AlertEventEnum;
use App\Models\Project;
use App\Services\AlertDispatcherService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts the 4xx answers of the push endpoints (#117) for the System page, around the throttle
 * and the token check, so 429, 401, 403, 413 and 422 are all seen. The reason is a fixed word
 * or, for a 422, the first invalid field with list indexes as `*`
 * (`runtime.aggregates.*.count`): field names come from the push schema, never values, the body
 * or the token. Recording never changes the answer.
 *
 * Token rejections also raise `push.rejected` (#22) once `excubia.push_rejected_alert_after`
 * of them come within an hour: per project for a disabled one (403), per client for a missing,
 * unknown or revoked token (401), which has no project and so alerts on the system project
 * (#70). The client is only a hashed cache key that expires with the hour: no IP is stored.
 */
class RecordIngestRejectionMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if ($status >= 400 && $status < 500) {
            try {
                app(RecordIngestRejectionAction::class)->execute(
                    (string) $request->segment(2),
                    $status,
                    $this->reason($response),
                );
                $this->alertTokenRejections($request, $status);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }

    /**
     * Counts a 401 or 403 in its hour and raises `push.rejected` when the count reaches the
     * threshold, so one burst alerts once (the hourly alert throttle covers several clients).
     */
    private function alertTokenRejections(Request $request, int $status): void
    {
        $isDisabled = $status === Response::HTTP_FORBIDDEN;

        if (! $isDisabled && $status !== Response::HTTP_UNAUTHORIZED) {
            return;
        }

        $token = $request->bearerToken();
        $project = $isDisabled
            ? ($token === null ? null : Project::query()->where('push_token_hash', hash('sha256', $token))->first())
            : Project::query()->where('is_system', true)->first();

        if ($project === null) {
            return;
        }

        $threshold = max(1, (int) config('excubia.push_rejected_alert_after'));
        $key = 'push-rejected:'.($isDisabled ? 'project:'.$project->id : 'client:'.hash('sha256', (string) $request->ip()));

        if (RateLimiter::hit($key, 3600) !== $threshold) {
            return;
        }

        $summary = $isDisabled
            ? "{$threshold} pushes to this disabled project within an hour"
            : "{$threshold} pushes with a missing, unknown or revoked token from one client within an hour";

        app(AlertDispatcherService::class)->dispatch(AlertEventEnum::PushRejected, $project, $summary, [
            'reason' => $isDisabled ? 'disabled' : 'token',
            'count' => $threshold,
            'endpoint' => (string) $request->segment(2),
        ], subject: $isDisabled ? 'disabled' : 'token');
    }

    private function reason(Response $response): string
    {
        return match ($response->getStatusCode()) {
            Response::HTTP_UNAUTHORIZED => 'token',
            Response::HTTP_FORBIDDEN => 'disabled',
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'too_large',
            Response::HTTP_TOO_MANY_REQUESTS => 'throttled',
            Response::HTTP_UNPROCESSABLE_ENTITY => $this->invalidField($response),
            default => 'other',
        };
    }

    /**
     * The first key of the validation errors, e.g. `runtime.aggregates.*.count`, or `invalid`.
     */
    private function invalidField(Response $response): string
    {
        $errors = $response instanceof JsonResponse ? ($response->getData(true)['errors'] ?? null) : null;
        $field = is_array($errors) ? array_key_first($errors) : null;

        return is_string($field) ? (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $field) : 'invalid';
    }
}
