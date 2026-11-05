<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Connectors\SearchConsoleConnector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Dane z Google Search Console dla klienta (także bez usługi reklam – np. SEO / opieka). */
class SyncSearchConsoleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public Client $client) {}

    public function uniqueId(): string
    {
        return 'gsc-'.$this->client->id;
    }

    public function handle(SearchConsoleConnector $gsc): void
    {
        $integration = $this->client->integration('google');
        try {
            $gsc->sync($this->client);
            $integration?->update(['last_synced_at' => now(), 'last_error' => null]);
        } catch (Throwable $e) {
            Log::warning("Search Console klienta {$this->client->id}: ".$e->getMessage());
            $integration?->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
