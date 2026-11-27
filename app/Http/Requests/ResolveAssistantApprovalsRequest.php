<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\AssistantConversationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The user's decision on the write calls an assistant turn waits on (#320): approve or reject
 * them all, and with `always` stop asking for those tools in this conversation. Only in the
 * user's own conversations, while the assistant is on and the user has `can-use-ai`.
 */
class ResolveAssistantApprovalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) config('excubia.ai_enabled')
            && $user instanceof User
            && $user->can(PermissionEnum::CanUseAi->value)
            && app(AssistantConversationService::class)->belongsTo($this->conversationId(), $user);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'approved' => ['required', 'boolean'],
            'always' => ['sometimes', 'boolean'],
        ];
    }

    public function conversationId(): string
    {
        return (string) $this->route('conversation');
    }
}
