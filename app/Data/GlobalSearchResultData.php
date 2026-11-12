<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One result of the command palette's search (#274): a record the user may open, with the
 * page that shows it. A project result carries its `avatar` (#287): the slug that picks its
 * colour and its logo URL.
 */
final readonly class GlobalSearchResultData
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description,
        public string $url,
        /** @var array{slug: string, logoUrl: string|null}|null */
        public ?array $avatar = null,
    ) {}

    /**
     * @return array{id: string, label: string, description: string|null, url: string, avatar: array{slug: string, logoUrl: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'url' => $this->url,
            'avatar' => $this->avatar,
        ];
    }
}
