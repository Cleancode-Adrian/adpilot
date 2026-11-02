<?php

namespace App\Jobs;

use App\Services\SystemStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Puste zadanie co 5 minut – potwierdza, że worker kolejki żyje (strona Status). */
class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        SystemStatus::beat(SystemStatus::WORKER_KEY);
    }
}
