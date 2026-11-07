<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExceptionGroup;
use App\Services\FixSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An exception group for the project page (#26): the masked message, the frames with relative
 * paths, the context, the counts and status, the first, last and regressed release (#106), and
 * a ready prompt to fix it.
 *
 * @mixin ExceptionGroup
 */
class ExceptionGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ExceptionGroup $group */
        $group = $this->resource;

        return [
            'id' => $group->id,
            'fingerprint' => $group->fingerprint,
            'environment' => $group->environment,
            'class' => $group->class,
            'message' => $group->message,
            'frames' => $group->frames,
            'previous' => $group->previous,
            'context' => ['kind' => $group->context_kind, 'name' => $group->context_name],
            'count' => $group->count,
            'first_seen' => $group->first_seen->toIso8601String(),
            'last_seen' => $group->last_seen->toIso8601String(),
            'first_release' => $group->first_release,
            'last_release' => $group->last_release,
            'regressed_release' => $group->regressed_release,
            'status' => $group->status->value,
            'resolved_at' => $group->resolved_at?->toIso8601String(),
            'fix' => app(FixSuggestionService::class)->forException($group),
        ];
    }
}
