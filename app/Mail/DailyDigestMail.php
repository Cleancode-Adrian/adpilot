<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Poranne podsumowanie: pilne zadania, problemy z integracjami, nowe zapytania. */
class DailyDigestMail extends Mailable
{
    public function __construct(public array $digest) {}

    public function envelope(): Envelope
    {
        $n = $this->digest['urgent_count'];

        return new Envelope(subject: Setting::brand()['name'].': '.($n ? "$n pilnych zadań" : 'konta w porządku').' – '.now()->format('d.m'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-digest', with: $this->digest + ['url' => route('dashboard'), 'statusUrl' => route('admin.status')]);
    }
}
