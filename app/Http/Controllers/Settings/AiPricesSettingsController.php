<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsProjectSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateAiPricesSettingsRequest;
use App\Services\AiPriceService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI prices (#129): the editable price table per provider and model that turns the
 * AI usage of the projects into estimated costs, with the seeded defaults to restore. The routes
 * require `can-manage-alerts`.
 */
class AiPricesSettingsController extends Controller
{
    use LogsProjectSettingsUpdateTrait;

    public function edit(AiPriceService $prices): Response
    {
        return Inertia::render('settings/ai-prices', [
            'prices' => $prices->prices(),
            'defaults' => $prices->defaults(),
            'updatedOn' => $prices->updatedOn(),
            'defaultsUpdatedOn' => AiPriceService::DEFAULTS_UPDATED_ON,
        ]);
    }

    public function update(UpdateAiPricesSettingsRequest $request, SettingsRepositoryService $settings): RedirectResponse
    {
        $values = $request->settings();
        $settings->save($values);
        $this->logProjectSettingsUpdate($request, 'ai-prices', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI prices saved.')]);

        return to_route('ai-prices.edit');
    }
}
