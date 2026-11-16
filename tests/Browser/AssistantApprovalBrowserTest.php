<?php

declare(strict_types=1);

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Enums\RoleEnum;
use App\Models\ExceptionGroup;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;

require_once __DIR__.'/BrowserSeedHelpers.php';

/**
 * The approval of an assistant write call (#320): the turn shows the call under the answer in the
 * registry Confirmation, with the "don't ask again in this chat" checkbox, and Approve resumes the
 * turn, in light and dark, with no serious axe issue. The provider is faked.
 */
it('asks before a write call and resumes the turn once approved', function (string $scheme) {
    config(['excubia.ai_enabled' => true]);
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $this->actingAs($admin);
    $project = Project::factory()->create(['slug' => 'shop']);
    $group = ExceptionGroup::query()->create([
        'project_id' => $project->id, 'fingerprint' => str_repeat('a', 64), 'environment' => 'production',
        'class' => 'RuntimeException', 'message' => 'Payment failed', 'frames' => [], 'previous' => [],
        'context_kind' => 'request', 'context_name' => 'checkout.store', 'count' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => 'open',
    ]);
    ExcubiaAssistantAgent::fake([
        new ToolCall('call_1', 'UpdateExceptionStatusTool', ['project' => 'shop', 'ids' => [$group->id], 'status' => 'resolved']),
        'Resolved the payment exception.',
    ]);
    $this->post(route('ai.store'), ['message' => 'Resolve the payment exception on shop']);
    $conversationId = (string) Conversation::query()->sole()->getKey();

    $page = visitA11y(route('ai.show', $conversationId, false), $scheme)
        ->assertSee('Waiting for your approval.')
        ->assertSeeIn('[data-test="assistant-approvals"]', 'Approve this change?')
        ->assertSeeIn('[data-test="assistant-approvals"]', 'Change the status of exceptions')
        ->assertSeeIn('[data-test="assistant-approvals"]', 'status: resolved')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues(1);
    assertAxeCheckedColorContrast($page);

    $page->click("Don't ask again for these tools in this chat")
        ->click('Approve')
        ->waitForText('Resolved the payment exception.')
        ->assertMissing('[data-test="assistant-approvals"]')
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);
