<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An activity log entry for the activity log page, as in qiick-io/externa-core.
 *
 * @mixin Activity
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array{
     *     public_id: string,
     *     event: string|null,
     *     description: string,
     *     log_name: string|null,
     *     created_at: string|null,
     *     causer: mixed,
     *     subject: mixed,
     *     changes: array<string, mixed>,
     *     properties: array{ip: mixed, user_agent: mixed, meta: array<string, mixed>}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;
        $properties = $activity->properties?->toArray() ?? [];

        return [
            // F8: never the internal bigint; the package hides `id` for the same reason.
            'public_id' => $activity->public_id,
            'event' => $activity->event,
            'description' => $activity->description,
            'log_name' => $activity->log_name,
            'created_at' => $activity->created_at?->toIso8601String(),
            'causer' => $this->whenLoaded('causer', fn () => $activity->causer instanceof User ? [
                'id' => $activity->causer->id,
                'name' => $activity->causer->name,
                'email' => $activity->causer->email,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn () => $activity->subject !== null ? [
                'type' => class_basename((string) $activity->subject_type),
                'id' => $activity->subject_id,
                'label' => $this->subjectLabel($activity->subject),
            ] : null),
            'changes' => $activity->attribute_changes?->toArray() ?? [],
            'properties' => [
                'ip' => $properties['ip'] ?? null,
                'user_agent' => $properties['user_agent'] ?? null,
                // Product payload only. qiick/laravel-audit always writes the reserved
                // attribution keys (design §3.1); they are not "field changes" meta.
                'meta' => collect($properties)->except([
                    'ip',
                    'user_agent',
                    'actor_kind',
                    'api_key_id',
                    'api_key_prefix',
                    'api_key_created_by',
                    'oauth_client_id',
                ])->all(),
            ],
        ];
    }

    /**
     * A readable label for the subject: its name, or `#id`.
     */
    private function subjectLabel(Model $subject): string
    {
        $name = $subject->getAttribute('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : '#'.$subject->getKey();
    }
}
