<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\AlertWebhookService;
use Illuminate\Support\Facades\DB;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookSubscription;

/**
 * Saves the alert webhook URL from Settings → Integrations (#22): creates Excubia's one
 * instance-level qiick/laravel-outbound endpoint, subscribed to every alert type, on the first
 * URL; changes its URL afterwards; deletes it when the URL is cleared (its deliveries and
 * secrets go with it). The signing secret is generated separately (IssueAlertWebhookSecretAction).
 *
 * `created_by` is the acting user, never request input: the package emails that user when it
 * disables the endpoint after a long failure streak.
 */
class SaveAlertWebhookAction
{
    public function __construct(private readonly AlertWebhookService $webhooks) {}

    public function execute(?string $url, User $actor): ?WebhookEndpoint
    {
        return DB::transaction(function () use ($url, $actor): ?WebhookEndpoint {
            $endpoint = $this->webhooks->endpoint();

            if ($url === null) {
                $endpoint?->delete();

                return null;
            }

            if ($endpoint !== null) {
                $endpoint->fill(['url' => $url])->save();

                return $endpoint;
            }

            $endpoint = (new WebhookEndpoint)->fill(['url' => $url, 'name' => 'Excubia alerts']);
            $endpoint->forceFill(['created_by' => $actor->id])->save();
            (new WebhookSubscription)
                ->fill(['event_type' => WebhookSubscription::ALL_EVENT_TYPES])
                ->forceFill(['endpoint_id' => $endpoint->id])
                ->save();

            return $endpoint;
        });
    }
}
