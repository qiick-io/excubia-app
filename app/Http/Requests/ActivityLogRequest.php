<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ActivityEventEnum;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the activity log filters (user, action, log, date range) on top of search and
 * pagination. An unknown action is ignored rather than rejected, as in qiick-io/externa-core.
 * The controller authorizes through ActivityPolicy.
 */
class ActivityLogRequest extends ListQueryRequest
{
    /**
     * Log names written by the app: models (`default`), logins (`auth`) and settings.
     *
     * @var list<string>
     */
    public const LOG_NAMES = ['default', 'auth', 'settings'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'string'],
            'log_name' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The filters for ActivityLogService and the page, normalized.
     *
     * @return array{search: string, user_ids: list<int>, user_id: int|null, event: string, log_name: string, date_from: string, date_to: string}
     */
    public function activityFilters(): array
    {
        $userIds = $this->userIds();
        $event = $this->string('event')->toString();
        $logName = $this->string('log_name')->toString();

        return [
            'search' => $this->search(),
            'user_ids' => $userIds,
            'user_id' => count($userIds) === 1 ? $userIds[0] : null,
            'event' => ActivityEventEnum::tryFrom($event) !== null ? $event : '',
            'log_name' => in_array($logName, self::LOG_NAMES, true) ? $logName : '',
            'date_from' => $this->string('date_from')->toString(),
            'date_to' => $this->string('date_to')->toString(),
        ];
    }

    /**
     * Causer ids from `user_ids[]`, or the single `user_id` of a deep link.
     *
     * @return list<int>
     */
    private function userIds(): array
    {
        $raw = $this->input('user_ids', $this->input('user_id'));
        $ids = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0,
        )));
    }
}
