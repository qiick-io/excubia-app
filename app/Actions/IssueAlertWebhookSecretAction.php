<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Services\AlertWebhookService;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Webhooks\SecretRotator;
use Qiick\Outbound\Webhooks\WebhookException;

/**
 * Generates the alert webhook's signing secret (Standard Webhooks: the sender generates it and
 * shows it once). The first one is issued; later ones rotate, and the previous secret keeps
 * signing for the package's grace period (24 h), so the receiver can switch without a gap.
 *
 * The returned `whsec_` value is shown once and never stored in plain text, logged or put in
 * the activity log.
 */
class IssueAlertWebhookSecretAction
{
    public function __construct(
        private readonly AlertWebhookService $webhooks,
        private readonly SecretRotator $rotator,
    ) {}

    /**
     * @return array{secret: string, reason: 'created'|'rotated'}|null null without an endpoint
     *
     * @throws WebhookException when the endpoint already holds the most signing secrets allowed
     */
    public function execute(User $actor): ?array
    {
        $endpoint = $this->webhooks->endpoint();

        if (! $endpoint instanceof WebhookEndpoint) {
            return null;
        }

        $isRotation = $this->webhooks->isReady();
        $secret = $isRotation ? $this->rotator->rotate($endpoint) : $this->rotator->issue($endpoint);

        activity('settings')
            ->causedBy($actor)
            ->event($isRotation ? ActivityEventEnum::WebhookSecretRotated->value : ActivityEventEnum::WebhookSecretCreated->value)
            ->withProperties(['endpoint' => $endpoint->public_id])
            ->log($isRotation ? 'Alert webhook secret rotated' : 'Alert webhook secret created');

        $plainSecret = $secret->secret ?? throw new WebhookException('The package issued a secret without its value.');

        return ['secret' => $plainSecret, 'reason' => $isRotation ? 'rotated' : 'created'];
    }
}
