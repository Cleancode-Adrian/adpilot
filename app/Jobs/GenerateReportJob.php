<?php

namespace App\Jobs;

use App\Mail\ReportReadyMail;
use App\Models\Client;
use App\Services\Reports\ReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public Client $client,
        public string $type,
        public string $from,
        public string $to,
        public bool $send = false,
    ) {}

    public function handle(ReportBuilder $builder): void
    {
        $report = $builder->build($this->client, $this->type, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));

        if ($this->send && $this->type === 'client' && $this->client->contact_email) {
            Mail::to($this->client->contact_email)->send(new ReportReadyMail($report));
            $report->update(['sent_at' => now()]);
        }
    }
}
