<?php

declare(strict_types=1);

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;

/**
 * The assistant chat (#17): off by default, behind `can-use-ai`, with a faked provider so no test
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

    $this->actingAs($admin)->get(route('ai.index'))->assertNotFound();
    $this->actingAs($admin)->post(route('ai.store'), ['message' => 'Hello'])->assertForbidden();
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('isAssistantEnabled', false));

    ExcubiaAssistantAgent::assertNeverPrompted();
});

it('keeps the assistant behind can-use-ai', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake()->preventStrayPrompts();
    $reader = assistantUser(RoleEnum::Reader);

    $this->actingAs($reader)->get(route('ai.index'))->assertForbidden();
    $this->actingAs($reader)->post(route('ai.store'), ['message' => 'Hello'])->assertForbidden();

    ExcubiaAssistantAgent::assertNeverPrompted();
});

it('answers a question and stores the conversation for the user', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['Shop has one high finding.']);
    $admin = assistantUser();

    $response = $this->actingAs($admin)->post(route('ai.store'), ['message' => 'Which projects have high findings?']);

    $conversation = Conversation::query()->sole();
    $response->assertRedirect(route('ai.show', $conversation->getKey()));
    ExcubiaAssistantAgent::assertPrompted('Which projects have high findings?');

    $this->actingAs($admin)->get(route('ai.show', $conversation->getKey()))
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

    $this->actingAs($admin)->post(route('ai.store'), ['message' => 'First question']);
    $conversationId = Conversation::query()->sole()->getKey();

    $this->actingAs($admin)->post(route('ai.store'), ['message' => 'Second question', 'conversation_id' => $conversationId])
        ->assertRedirect(route('ai.show', $conversationId));

    $this->actingAs($admin)->get(route('ai.show', $conversationId))
        ->assertInertia(fn (Assert $page) => $page->has('messages', 4)->where('messages.3.content', 'Second answer.'));
});

it('shows and continues only the user own conversations', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['Answer.']);
    $owner = assistantUser();
    $other = assistantUser();

    $this->actingAs($owner)->post(route('ai.store'), ['message' => 'Private question']);
    $conversationId = Conversation::query()->sole()->getKey();

    $this->actingAs($other)->get(route('ai.show', $conversationId))->assertNotFound();
    $this->actingAs($other)->post(route('ai.store'), ['message' => 'x', 'conversation_id' => $conversationId])
        ->assertSessionHasErrors('conversation_id');
    $this->actingAs($other)->get(route('ai.index'))
        ->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
});

it('validates the message', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake()->preventStrayPrompts();
    $admin = assistantUser();

    $this->actingAs($admin)->post(route('ai.store'), ['message' => ''])->assertSessionHasErrors('message');
    $this->actingAs($admin)->post(route('ai.store'), ['message' => str_repeat('a', 4001)])->assertSessionHasErrors('message');

    ExcubiaAssistantAgent::assertNeverPrompted();
});

it('tells the user when the provider fails', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(fn () => throw new RuntimeException('connection refused'));

    $this->actingAs(assistantUser())->post(route('ai.store'), ['message' => 'Hello'])
        ->assertRedirect(route('ai.index'))
        ->assertInertiaFlash('toast.type', 'error');
});

it('answers the side panel with JSON and keeps it on the same conversation', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['First answer.', 'Second answer.']);
    $admin = assistantUser();

    $first = $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'First question'])
        ->assertOk()
        ->assertJsonPath('messages.0.content', 'First question')
        ->assertJsonPath('messages.1.content', 'First answer.');

    $conversationId = Conversation::query()->sole()->getKey();
    expect($first->json('conversationId'))->toBe($conversationId);

    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => 'Second question', 'conversation_id' => $conversationId])
        ->assertOk()
        ->assertJsonCount(4, 'messages')
        ->assertJsonPath('messages.3.content', 'Second answer.');

    $this->actingAs($admin)->postJson(route('ai.store'), ['message' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

it('tells the side panel when the provider fails', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(fn () => throw new RuntimeException('connection refused'));

    $this->actingAs(assistantUser())->postJson(route('ai.store'), ['message' => 'Hello'])
        ->assertStatus(503)
        ->assertJsonStructure(['message']);
});

it('lives at /ai and redirects the old /assistant links', function () {
    config(['excubia.ai_enabled' => true]);
    $admin = assistantUser();

    expect(route('ai.index', absolute: false))->toBe('/ai');

    $this->actingAs($admin)->get('/assistant')->assertRedirect('/ai');
    $this->actingAs($admin)->get('/assistant/abc')->assertRedirect(route('ai.show', 'abc'));
});

it('deletes only the selected conversations of the user', function () {
    config(['excubia.ai_enabled' => true]);
    ExcubiaAssistantAgent::fake(['One.', 'Two.', 'Three.']);
    $owner = assistantUser();
    $other = assistantUser();

    $this->actingAs($owner)->post(route('ai.store'), ['message' => 'First']);
    $this->actingAs($owner)->post(route('ai.store'), ['message' => 'Second']);
    $this->actingAs($other)->post(route('ai.store'), ['message' => 'Not yours']);
    [$first, $second] = Conversation::query()->where('participant_id', $owner->id)->orderBy('created_at')->pluck('id')->all();
    $foreign = Conversation::query()->where('participant_id', $other->id)->value('id');

    $this->actingAs($owner)
        ->delete(route('ai.conversations.destroy'), ['ids' => [$first, $foreign], 'current' => $first])
        ->assertRedirect(route('ai.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(Conversation::query()->pluck('id')->sort()->values()->all())->toBe(collect([$second, $foreign])->sort()->values()->all())
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $first)->count())->toBe(0);

    $this->actingAs($owner)->delete(route('ai.conversations.destroy'), ['ids' => []])->assertSessionHasErrors('ids');
});

it('uses only the tools enabled in Settings → AI', function () {
    $admin = assistantUser();

    expect(collect((new ExcubiaAssistantAgent($admin))->tools())->count())->toBe(count(ExcubiaAssistantAgent::TOOLS));

    $this->actingAs(assistantUser(RoleEnum::SuperAdmin))
        ->put(route('ai-settings.update'), ['tools' => ['list-projects', 'search-findings']])
        ->assertRedirect(route('ai-settings.edit'));

    expect(collect((new ExcubiaAssistantAgent($admin))->tools())->map(fn ($tool): string => $tool::class)->all())
        ->toBe([ExcubiaAssistantAgent::TOOLS['list-projects'], ExcubiaAssistantAgent::TOOLS['search-findings']]);
});

it('shows the AI settings to project settings managers only', function () {
    config(['excubia.ai_enabled' => true, 'ai.default' => 'ollama']);

    $this->actingAs(assistantUser(RoleEnum::SuperAdmin))->get(route('ai-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/ai')
            ->where('isEnabled', true)
            ->where('provider', 'ollama')
            ->has('tools', count(ExcubiaAssistantAgent::TOOLS))
            ->where('enabledTools', array_keys(ExcubiaAssistantAgent::TOOLS)));

    $this->actingAs(assistantUser())->get(route('ai-settings.edit'))->assertForbidden();
    $this->actingAs(assistantUser(RoleEnum::SuperAdmin))
        ->put(route('ai-settings.update'), ['tools' => ['delete-everything']])
        ->assertSessionHasErrors('tools.0');
});
