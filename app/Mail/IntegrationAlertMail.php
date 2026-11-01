<?php

namespace App\Mail;

use App\Models\Integration;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class IntegrationAlertMail extends Mailable
{
    public function __construct(public Integration $integration) {}

    public function envelope(): Envelope
    {
        $p = $this->integration->provider === 'google' ? 'Google' : 'Meta';

        return new Envelope(subject: "⚠️ {$this->integration->client->name}: problem z połączeniem {$p}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.integration-alert', with: [
            'i' => $this->integration,
            'url' => route('clients.integrations', $this->integration->client_id),
        ]);
    }
}
