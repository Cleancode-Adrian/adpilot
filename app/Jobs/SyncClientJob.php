<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncClientJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 2;

    public function __construct(public Client $client) {}

    public function uniqueId(): string
    {
        return (string) $this->client->id;
    }

    public function handle(ClientSync $sync): void
    {
        $sync->sync($this->client);
    }
}
