<?php

namespace App\Jobs;

use App\Models\TrackingAudit;
use App\Services\Analysis\RuleEngine;
use App\Services\Tracking\TrackingAuditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunTrackingAuditJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public TrackingAudit $audit) {}

    public function handle(TrackingAuditor $auditor, RuleEngine $engine): void
    {
        $this->audit->update(['status' => 'running']);
        try {
            $results = $auditor->audit($this->audit->url, $this->audit->client->goal);
            $this->audit->update(['status' => 'done', 'score' => $results['score'], 'results' => $results, 'error' => null]);
            $engine->run($this->audit->client);
        } catch (Throwable $e) {
            $this->audit->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
