<?php

namespace App\Jobs;

use App\Models\SiteAudit;
use App\Services\SiteAudit\SiteAuditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSiteAuditJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public SiteAudit $audit) {}

    public function handle(SiteAuditor $auditor): void
    {
        $auditor->run($this->audit);
    }
}
