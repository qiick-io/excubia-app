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
 * One alert (#22) mailed to a subscriber, in their locale, or every alert of one report push in
 * one mail (`$alerts`, worst first). Sent by DeliverAlertJob, which is already queued, so this
 * mailable is not queued itself.
 */
class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $alert  the alert payload of AlertDispatcherService
     * @param  string  $summary  the alert's one-line summary
     * @param  list<array{id: string, event: string, severity: string, summary: string}>  $alerts  every alert of a grouped mail; empty for one alert
     */
    public function __construct(public readonly array $alert, public readonly string $summary, public readonly array $alerts = []) {}

    public function envelope(): Envelope
    {
        $project = is_array($this->alert['project'] ?? null) ? $this->alert['project']['name'] : app(SettingsRepositoryService::class)->appName();
        $what = $this->alerts === [] ? $this->alert['event'] : __(':count alerts', ['count' => count($this->alerts)]);

        return new Envelope(subject: sprintf('[%s] %s · %s', Str::upper((string) $this->alert['severity']), $what, $project));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
