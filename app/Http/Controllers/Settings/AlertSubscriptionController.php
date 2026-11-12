<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\SaveAlertSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAlertSubscriptionRequest;
use App\Jobs\DeliverAlertJob;
use App\Models\Project;
use App\Models\User;
use App\Services\AlertDispatcherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Alerts of the signed-in user, in Settings → Profile: saves their email (#22) and in-app (#296)
 * toggles and their filters, and queues a test mail to themselves.
 */
class AlertSubscriptionController extends Controller
{
    public function update(UpdateAlertSubscriptionRequest $request, SaveAlertSubscriptionAction $save): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $save->execute($user, $request->boolean('has_alert_emails'), $request->boolean('has_alert_in_app', $user->has_alert_in_app), $request->filter());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert preferences saved.')]);

        return to_route('profile.edit');
    }

    public function sendTest(Request $request, AlertDispatcherService $alerts): RedirectResponse
    {
        Gate::authorize('viewAny', Project::class);

        /** @var User $user */
        $user = $request->user();
        $alerts->test(DeliverAlertJob::EMAIL.':'.$user->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email queued. It needs a queue worker.')]);

        return to_route('profile.edit');
    }
}
