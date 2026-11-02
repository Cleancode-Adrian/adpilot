<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Powiadomienie bezpieczeństwa konta (nowe urządzenie, zmiana hasła, 2FA, blokada logowania). */
class SecurityAlertMail extends Mailable
{
    public function __construct(public string $title, public array $lines, public ?string $action = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🔒 '.Setting::brand()['name'].': '.$this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.security-alert', with: [
            'url' => route('security.edit'),
            'agency' => Setting::brand()['name'],
        ]);
    }
}
