<?php

namespace App\Mail;

use App\Models\Quote;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class QuoteMail extends Mailable
{
    public function __construct(public Quote $quote) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Oferta '.$this->quote->number.': '.$this->quote->title.' – '.Setting::brand()['name']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quote', with: [
            'url' => route('public.quote', $this->quote->token),
            'agency' => Setting::brand()['name'],
        ]);
    }
}
