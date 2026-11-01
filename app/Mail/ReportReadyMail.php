<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Raport z działań reklamowych: '.$this->report->period_start->format('d.m.Y').' – '.$this->report->period_end->format('d.m.Y'),
        );
    }

    public function content(): Content
    {
        $client = $this->report->client;

        return new Content(markdown: 'mail.report-ready', with: [
            'client' => $client,
            'report' => $this->report,
            'url' => route('public.report', [$client->report_token, $this->report]),
            'pdfUrl' => route('public.report.pdf', [$client->report_token, $this->report]),
        ]);
    }
}
