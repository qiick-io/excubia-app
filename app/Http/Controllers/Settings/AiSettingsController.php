<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Ai\Agents\ExcubiaAssistantAgent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateAiSettingsRequest;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI, laid out as externa-core's AI tools page: the assistant's status (on or off,
 * provider and model, from `.env`) and the read-only tools it may use. The routes require
 * `can-manage-project-settings`.
 */
class AiSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $provider = (string) config('ai.default');

        return Inertia::render('settings/ai', [
            'isEnabled' => (bool) config('excubia.ai_enabled'),
            'provider' => $provider,
            'model' => (string) config("ai.providers.{$provider}.models.text.default", ''),
            'enabledTools' => $this->settings->aiEnabledTools(),
            'tools' => array_map(
                fn (string $name, string $class): array => ['name' => $name, 'description' => (string) (new $class($user))->description()],
                array_keys(ExcubiaAssistantAgent::TOOLS),
                ExcubiaAssistantAgent::TOOLS,
            ),
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
