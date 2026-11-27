<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\ExceptionInboxData;
use App\Enums\ExceptionStatusEnum;

/** Normalizes the exception inbox's shareable filters and standard page sizes. */
class ExceptionInboxQueryRequest extends ListQueryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', 'string'],
            'assigned' => ['nullable', 'string'],
            'assignee' => ['nullable', 'string', 'max:1000'],
            'new' => ['nullable', 'string'],
        ];
    }

    public function filtersFor(int $userId): ExceptionInboxData
    {
        $status = $this->string('status')->toString();
        $assignees = explode(',', $this->string('assignee')->toString());
        $assigneeIds = [];

        foreach ($assignees as $assignee) {
            $assigneeId = filter_var($assignee, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($assigneeId !== false) {
                $assigneeIds[] = $assigneeId;
            }
        }

        return new ExceptionInboxData(
            status: $status === 'all' ? null : (ExceptionStatusEnum::tryFrom($status) ?? ExceptionStatusEnum::Open),
            search: $this->search(),
            assignedUserId: $this->query('assigned') === 'me' ? $userId : null,
            assigneeIds: array_values(array_unique($assigneeIds)),
            shouldIncludeUnassigned: in_array('none', $assignees, true),
            isNewOnly: $this->query('new') === '24h',
            assigneeSortDirection: $this->query('sort') === 'assignee' ? $this->sortDirection('asc') : null,
            perPage: $this->perPage(),
        );
    }
}
