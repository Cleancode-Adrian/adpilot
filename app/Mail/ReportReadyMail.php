<?php

namespace App\Mail;

use App\Models\Report;
use App\Models\Setting;
use App\Services\Reports\WorkReportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    public function envelope(): Envelope
    {
        $brand = Setting::brandFor($this->report->client);

        return new Envelope(
            // white label: odpowiedzi trafiają do agencji, a nazwa nadawcy to nazwa agencji
            from: $brand['white_label'] ? new Address((string) config('mail.from.address'), $brand['name']) : null,
            replyTo: $brand['white_label'] && $brand['email'] ? [new Address($brand['email'], $brand['name'])] : [],
            subject: $this->report->type === 'work'
                ? 'Raport z prac – '.$this->report->client->name.' – '.WorkReportBuilder::monthLabel($this->report->period_start)
                : 'Raport z działań reklamowych: '.$this->report->period_start->format('d.m.Y').' – '.$this->report->period_end->format('d.m.Y'),
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
            'agency' => ($brand = Setting::brandFor($client))['name'],
            'brandUrl' => $brand['white_label'] && $brand['website'] ? 'https://'.$brand['website'] : null,
        ]);
    }
}
