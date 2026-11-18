<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ApiKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns that a personal API key will expire soon (#351): database (bell) and mail. Never carries
 * the plaintext secret — only the public prefix, name and expiry.
 */
class ApiKeyExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ApiKey $apiKey) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $this->apiKey->expires_at?->toFormattedDateString() ?? '';

        return (new MailMessage)
            ->subject(__('API key “:name” expires soon', ['name' => $this->apiKey->name]))
            ->line(__('Your API key “:name” (:prefix) expires on :date.', [
                'name' => $this->apiKey->name,
                'prefix' => $this->apiKey->key_prefix,
                'date' => $expiresAt,
            ]))
            ->action(__('Manage API keys'), route('api-keys.index'));
    }

    /**
     * @return array{type: string, api_key_id: int, key_name: string, key_prefix: string, expires_at: string|null, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'api_key_expiring',
            'api_key_id' => $this->apiKey->id,
            'key_name' => $this->apiKey->name,
            'key_prefix' => $this->apiKey->key_prefix,
            'expires_at' => $this->apiKey->expires_at?->toIso8601String(),
            'url' => route('api-keys.index'),
        ];
    }
}
