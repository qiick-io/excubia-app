<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteAssistantConversationsAction;
use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Enums\PermissionEnum;
use App\Http\Requests\DeleteAssistantConversationsRequest;
use App\Http\Requests\StoreAssistantMessageRequest;
use App\Models\User;
use App\Services\AssistantConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Assistant chat (#17), behind `excubia.ai_enabled` (404 while off) and `can-use-ai`. The page posts
 * with Inertia and is redirected; the ⌘J side panel posts JSON and gets the thread back.
 * ponytail: the answer is awaited in the request (no streaming); stream it when answers get long.
 */
class AssistantController extends Controller
{
    public function __construct(private AssistantConversationService $conversations) {}

    public function index(Request $request): Response
    {
        return $this->render($this->authorizedUser($request), null);
    }

    public function show(Request $request, string $conversation): Response
    {
        $user = $this->authorizedUser($request);

        abort_unless($this->conversations->belongsTo($conversation, $user), 404);

        return $this->render($user, $conversation);
    }

    public function store(StoreAssistantMessageRequest $request): RedirectResponse|JsonResponse
    {
        /** @var User $user authorized by the request */
        $user = $request->user();
        $conversationId = $request->string('conversation_id')->toString();
        $agent = new ExcubiaAssistantAgent($user);
        $conversationId === '' ? $agent->forUser($user) : $agent->continue($conversationId, as: $user);

        try {
            $response = $agent->prompt($request->string('message')->toString());
        } catch (Throwable $exception) {
            // The question itself is not logged: only that the provider failed.
            Log::warning('Assistant provider call failed.', ['exception' => $exception::class]);

            if ($request->wantsJson()) {
                return response()->json(['message' => __('The assistant is not reachable. Check the AI provider settings.')], 503);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => __('The assistant is not reachable. Check the AI provider settings.')]);

            return $conversationId === '' ? to_route('ai.index') : to_route('ai.show', $conversationId);
        }

        $conversationId = $response->conversationId ?? $conversationId;

        if ($request->wantsJson()) {
            return response()->json([
                'conversationId' => $conversationId,
                'messages' => $this->conversations->messages($conversationId),
            ]);
        }

        return to_route('ai.show', $conversationId);
    }

    /**
     * Deletes the selected conversations of the user, as externa-core's bulk delete.
     */
    public function destroy(DeleteAssistantConversationsRequest $request, DeleteAssistantConversationsAction $deleteConversations): RedirectResponse
    {
        $user = $this->authorizedUser($request);
        $count = $deleteConversations->execute($user, $request->conversationIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} Chat deleted.|[2,*] :count chats deleted.', $count, ['count' => $count])]);

        return in_array($request->string('current')->toString(), $request->conversationIds(), true) ? to_route('ai.index') : back();
    }

    private function authorizedUser(Request $request): User
    {
        abort_unless((bool) config('excubia.ai_enabled'), 404);
        Gate::authorize(PermissionEnum::CanUseAi->value);

        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function render(User $user, ?string $conversationId): Response
    {
        return Inertia::render('assistant/index', [
            'conversations' => $this->conversations->conversationsFor($user),
            'conversationId' => $conversationId,
            'messages' => $conversationId === null ? [] : $this->conversations->messages($conversationId),
        ]);
    }
}
