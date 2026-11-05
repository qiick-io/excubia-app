<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Conversations to delete from the AI page (selection + trash, as externa-core). Ids of other
 * users' conversations are ignored by DeleteAssistantConversationsAction.
 */
class DeleteAssistantConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('excubia.ai_enabled') && (bool) $this->user()?->can(PermissionEnum::CanUseAi->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'max:64'],
            // The open conversation, to leave it when it is deleted.
            'current' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return list<string>
     */
    public function conversationIds(): array
    {
        return array_values(array_map('strval', (array) $this->input('ids')));
    }
}
