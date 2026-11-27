<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateAiSettingsRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI, laid out as externa-core's AI tools page: the assistant's status (on or off,
 * provider and model, from `.env`), the write switches (#320) and the read and write tools it and
 * the MCP server may use, for `can-manage-project-settings`; then the AI prices section (#331),
 * for `can-manage-alerts`, which says what the price table feeds and, until a project pushes AI
 * usage, shows an empty state instead of a link to the table (SettingPolicy::viewAi).
 */
class AiSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(Request $request, AiUsageService $aiUsage): Response
    {
        /** @var User $user */
        $user = $request->user();
        $provider = (string) config('ai.default');
        $canManageTools = $user->can('update', Setting::class);
        $canManagePrices = $user->can('manageAlerts', Setting::class);

        return Inertia::render('settings/ai', [
            'canManageTools' => $canManageTools,
            'canManagePrices' => $canManagePrices,
            'hasAiUsage' => $canManagePrices && $aiUsage->hasAnyUsage(),
            'isEnabled' => (bool) config('excubia.ai_enabled'),
            'provider' => $provider,
            'model' => (string) config("ai.providers.{$provider}.models.text.default", ''),
            'isWriteEnabled' => (bool) config('excubia.ai_write_enabled'),
            'isMcpWriteEnabled' => (bool) config('excubia.mcp_write_enabled'),
            'enabledTools' => $this->settings->aiEnabledTools(),
            'tools' => $canManageTools ? array_map(
                fn (string $name, string $class): array => ['name' => $name, 'kind' => $class::kind()->value, 'description' => (string) (new $class($user))->description()],
                array_keys(ExcubiaAssistantAgent::TOOLS),
                ExcubiaAssistantAgent::TOOLS,
            ) : [],
        ]);
    }

    public function update(UpdateAiSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logProjectSettingsUpdate($request, 'ai', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('ai-settings.edit');
    }
}
