<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\WpEvent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Natychmiastowy alert o krytycznym zdarzeniu na stronie WordPress klienta. */
class WpAlertMail extends Mailable
{
    public function __construct(public WpEvent $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '⚠ '.$this->event->client->name.': '.$this->event->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.wp-alert', with: [
            'event' => $this->event,
            'client' => $this->event->client,
            'url' => route('clients.wordpress', $this->event->client_id),
            'agency' => Setting::brand()['name'],
        ]);
    }
}
