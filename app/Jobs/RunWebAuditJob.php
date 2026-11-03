<?php

namespace App\Jobs;

use App\Models\WebAudit;
use App\Services\WebAudit\WebAuditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunWebAuditJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public WebAudit $audit) {}

    public function handle(WebAuditor $auditor): void
    {
        $auditor->run($this->audit);
    }

    public function failed(\Throwable $e): void
    {
        $this->audit->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
    }
}
