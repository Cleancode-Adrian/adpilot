<?php

namespace App\Jobs;

use App\Services\Monitoring\UptimeMonitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sprawdzenie dostępności wszystkich monitorowanych stron (co 5 min). */
class UptimeCheckJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 240;
    public int $tries = 1;
    public int $uniqueFor = 280;

    public function handle(UptimeMonitor $monitor): void
    {
        $monitor->checkAll();
    }
}
