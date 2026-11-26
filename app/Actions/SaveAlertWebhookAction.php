<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\AlertWebhookService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
    public function __construct(
        private readonly AlertWebhookService $webhooks,
        private readonly ReconcileAlertWebhookHistoryAction $reconcileHistory,
    ) {}

    /** Serializes saves: two managers saving the first URL at once must not create two endpoints. */
    public const LOCK = 'excubia:alert-webhook-endpoint';

    /**
     * @throws LockTimeoutException when another save holds the lock for longer than a few seconds
     */
    public function execute(?string $url, User $actor): ?WebhookEndpoint
    {
        return Cache::lock(self::LOCK, 10)->block(3, fn (): ?WebhookEndpoint => DB::transaction(function () use ($url, $actor): ?WebhookEndpoint {
            $endpoint = $this->webhooks->endpoint();

            if ($url === null) {
                $endpoint?->delete();
                // Its pending deliveries will never go out: their alerts stop reading `queued`.
                $this->reconcileHistory->execute();

                return null;
            }

            // Never null (null inherits, and outbound before beta.3 reads it as permission): the
            // operator's strict opt-in, which the package also enforces as the ceiling.
            $isPrivateHostAllowed = config('outbound.ssrf.allow_private_hosts') === true;

            if ($endpoint !== null) {
                $endpoint->fill(['url' => $url])->forceFill(['allow_private_hosts' => $isPrivateHostAllowed])->save();

                return $endpoint;
            }

            $endpoint = (new WebhookEndpoint)->fill(['url' => $url, 'name' => 'Excubia alerts']);
            $endpoint->forceFill(['created_by' => $actor->id, 'allow_private_hosts' => $isPrivateHostAllowed])->save();
            (new WebhookSubscription)
                ->fill(['event_type' => WebhookSubscription::ALL_EVENT_TYPES])
                ->forceFill(['endpoint_id' => $endpoint->id])
                ->save();

            return $endpoint;
        }));
    }
}
