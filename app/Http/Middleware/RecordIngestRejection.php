<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordIngestRejectionAction;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts the 4xx answers of the push endpoints (#117) for the System page, around the throttle
 * and the token check, so 429, 401, 403, 413 and 422 are all seen. The reason is a fixed word
 * or, for a 422, the first invalid field with list indexes as `*`
 * (`runtime.aggregates.*.count`): field names come from the push schema, never values, the body
 * or the token. Recording never changes the answer.
 */
class RecordIngestRejection
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
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $response;
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
