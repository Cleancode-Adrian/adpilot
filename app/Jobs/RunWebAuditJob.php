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

    public bool $deleteWhenMissingModels = true; // audyt usunięty, zanim przyszła jego kolej

    public function __construct(public WebAudit $audit) {}

    public function handle(WebAuditor $auditor): void
    {
        $auditor->run($this->audit);
        $this->continueBatch();
    }

    public function failed(\Throwable $e): void
    {
        $this->audit->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
        $this->continueBatch();
    }

    /** Audyt z listy zbiorczej: zapisz skrót wyniku przy pozycji i uruchom następną stronę. */
    private function continueBatch(): void
    {
        if (! $this->audit->audit_batch_id) {
            return;
        }
        $audit = WebAudit::find($this->audit->id); // lista mogła zostać usunięta w trakcie audytu
        $audit?->batchItem?->refreshSummary();
        $audit?->batch?->advance();
    }
}
