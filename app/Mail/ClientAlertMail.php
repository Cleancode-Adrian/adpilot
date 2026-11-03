<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Alert o stronie klienta (niedostępna, wróciła, certyfikat SSL). */
class ClientAlertMail extends Mailable
{
    public function __construct(public string $title, public array $lines, public string $url, public ?string $action = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.client-alert', with: ['agency' => Setting::brand()['name']]);
    }
}
