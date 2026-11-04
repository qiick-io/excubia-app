<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\AssistantConversationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A question to the assistant, optionally continuing one of the user's own conversations.
 */
class StoreAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('excubia.ai_enabled') && (bool) $this->user()?->can(PermissionEnum::AiUse->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $user = $this->user();

                    if (! $user instanceof User || ! app(AssistantConversationService::class)->belongsTo((string) $value, $user)) {
                        $fail(__('The conversation does not exist.'));
                    }
                },
            ],
        ];
    }
}
