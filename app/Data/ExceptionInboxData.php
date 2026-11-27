<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ExceptionStatusEnum;

/** The filters and page size of one authorized project's exception inbox. */
readonly class ExceptionInboxData
{
    /**
     * @param  list<int>  $assigneeIds
     * @param  'asc'|'desc'|null  $assigneeSortDirection
     */
    public function __construct(
        public ?ExceptionStatusEnum $status,
        public string $search,
        public ?int $assignedUserId,
        public array $assigneeIds,
        public bool $shouldIncludeUnassigned,
        public bool $isNewOnly,
        public ?string $assigneeSortDirection,
        public int $perPage,
    ) {}

    /** @return array{status: string, search: string, isMine: bool, assignees: list<string>, isNewOnly: bool, direction: string|null} */
    public function filters(): array
    {
        return [
            'status' => $this->status->value ?? 'all',
            'search' => $this->search,
            'isMine' => $this->assignedUserId !== null,
            'assignees' => [...array_map(strval(...), $this->assigneeIds), ...($this->shouldIncludeUnassigned ? ['none'] : [])],
            'isNewOnly' => $this->isNewOnly,
            'direction' => $this->assigneeSortDirection,
        ];
    }
}
