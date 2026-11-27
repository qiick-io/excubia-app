<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\SettingsRepositoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The weekly email digest (#114), in the user's locale: one section per project from
 * WeeklyDigestService::forUser().
 */
class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $projects  see WeeklyDigestService::forUser()
     */
    public function __construct(public readonly array $projects) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':app weekly digest', ['app' => app(SettingsRepositoryService::class)->appName()]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-digest');
    }
}
