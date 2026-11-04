<?php

declare(strict_types=1);

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;

/**
 * The assistant chat (#17): off by default, behind `ai.use`, with a faked provider so no test
 * makes a network call or a paid request.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Http::preventStrayRequests();
});

function assistantUser(RoleEnum $role = RoleEnum::Admin): User
{
    return User::factory()->create()->assignRole($role->value);
}

it('is disabled by default, with no page, no module and no outbound call', function () {
    ExcubiaAssistantAgent::fake()->preventStrayPrompts();
    $admin = assistantUser();

    $this->actingAs($admin)->get(route('assistant.index'))->assertNotFound();
    $this->actingAs($admin)->post(route('assistant.store'), ['message' => 'Hello'])->assertForbidden();
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('isAssistantEnabled', false));

    ExcubiaAssistantAgent::assertNeverPrompted();
});

it('keeps the assistant behind ai.use', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake()->preventStrayPrompts();
    $reader = assistantUser(RoleEnum::Reader);

    $this->actingAs($reader)->get(route('assistant.index'))->assertForbidden();
    $this->actingAs($reader)->post(route('assistant.store'), ['message' => 'Hello'])->assertForbidden();

    ExcubiaAssistantAgent::assertNeverPrompted();
});

it('answers a question and stores the conversation for the user', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['Shop has one high finding.']);
    $admin = assistantUser();

    $response = $this->actingAs($admin)->post(route('assistant.store'), ['message' => 'Which projects have high findings?']);

    $conversation = Conversation::query()->sole();
    $response->assertRedirect(route('assistant.show', $conversation->getKey()));
    ExcubiaAssistantAgent::assertPrompted('Which projects have high findings?');

    $this->actingAs($admin)->get(route('assistant.show', $conversation->getKey()))
        ->assertInertia(fn (Assert $page) => $page
            ->component('assistant/index')
            ->where('conversationId', $conversation->getKey())
            ->has('conversations', 1)
            ->where('messages.0.role', 'user')
            ->where('messages.0.content', 'Which projects have high findings?')
            ->where('messages.1.role', 'assistant')
            ->where('messages.1.content', 'Shop has one high finding.'));
});

it('continues a conversation', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['First answer.', 'Second answer.']);
    $admin = assistantUser();

    $this->actingAs($admin)->post(route('assistant.store'), ['message' => 'First question']);
    $conversationId = Conversation::query()->sole()->getKey();

    $this->actingAs($admin)->post(route('assistant.store'), ['message' => 'Second question', 'conversation_id' => $conversationId])
        ->assertRedirect(route('assistant.show', $conversationId));

    $this->actingAs($admin)->get(route('assistant.show', $conversationId))
        ->assertInertia(fn (Assert $page) => $page->has('messages', 4)->where('messages.3.content', 'Second answer.'));
});

it('shows and continues only the user own conversations', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['Answer.']);
    $owner = assistantUser();
    $other = assistantUser();

    $this->actingAs($owner)->post(route('assistant.store'), ['message' => 'Private question']);
    $conversationId = Conversation::query()->sole()->getKey();

    $this->actingAs($other)->get(route('assistant.show', $conversationId))->assertNotFound();
    $this->actingAs($other)->post(route('assistant.store'), ['message' => 'x', 'conversation_id' => $conversationId])
        ->assertSessionHasErrors('conversation_id');
    $this->actingAs($other)->get(route('assistant.index'))
        ->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
});

it('validates the message', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake()->preventStrayPrompts();
    $admin = assistantUser();

    $this->actingAs($admin)->post(route('assistant.store'), ['message' => ''])->assertSessionHasErrors('message');
    $this->actingAs($admin)->post(route('assistant.store'), ['message' => str_repeat('a', 4001)])->assertSessionHasErrors('message');

    ExcubiaAssistantAgent::assertNeverPrompted();
});

it('tells the user when the provider fails', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(fn () => throw new RuntimeException('connection refused'));

    $this->actingAs(assistantUser())->post(route('assistant.store'), ['message' => 'Hello'])
        ->assertRedirect(route('assistant.index'))
        ->assertInertiaFlash('toast.type', 'error');
});
