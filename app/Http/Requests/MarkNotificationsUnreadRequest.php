<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marks some of the signed-in user's in-app notifications unread again (#296). The ids are only
 * matched against the user's own rows.
 */
class MarkNotificationsUnreadRequest extends FormRequest
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
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['uuid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function notificationIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('ids')));
    }
}
