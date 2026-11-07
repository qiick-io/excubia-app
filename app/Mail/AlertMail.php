<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\SettingsRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * One alert (#22) mailed to a subscriber, in their locale. Sent by DeliverAlertJob, which is
 * already queued, so this mailable is not queued itself.
 */
class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $alert  the alert payload of AlertDispatcherService
     * @param  string  $summary  the alert's one-line summary
     */
    public function __construct(public readonly array $alert, public readonly string $summary) {}

    public function envelope(): Envelope
    {
        $project = is_array($this->alert['project'] ?? null) ? $this->alert['project']['name'] : app(SettingsRepositoryService::class)->appName();

        return new Envelope(subject: sprintf('[%s] %s · %s', Str::upper((string) $this->alert['severity']), $this->alert['event'], $project));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
