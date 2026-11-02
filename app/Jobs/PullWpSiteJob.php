<?php

namespace App\Jobs;

use App\Models\WpSite;
use App\Services\WordPress\WpMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Odpytanie strony WordPress (raport + skan plików). Błąd zapisuje się przy stronie – bez ponawiania. */
class PullWpSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public WpSite $site, public bool $scan = true) {}

    public function handle(WpMonitor $monitor): void
    {
        try {
            $monitor->pull($this->site, $this->scan);
        } catch (Throwable) {
            // komunikat jest już zapisany w last_error
        }
    }
}
