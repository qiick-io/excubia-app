<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marks the signed-in user's in-app notifications read (#296): the given `ids`, or every unread
 * one with `all: true`. The ids are only matched against the user's own rows.
 */
class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'accepted'],
            'ids' => ['required_without:all', 'array', 'max:100'],
            'ids.*' => ['uuid'],
        ];
    }

    /**
     * The ids to mark, or null for every unread notification.
     *
     * @return list<string>|null
     */
    public function notificationIds(): ?array
    {
        return $this->boolean('all') ? null : array_values(array_map('strval', (array) $this->validated('ids', [])));
    }
}
