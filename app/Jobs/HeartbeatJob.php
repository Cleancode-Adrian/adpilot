<?php

namespace App\Jobs;

use App\Services\SystemStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Puste zadanie co 5 minut – potwierdza, że worker kolejki żyje (strona Status). */
class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put(SystemStatus::WORKER_KEY, now(), now()->addDay());
    }
}
