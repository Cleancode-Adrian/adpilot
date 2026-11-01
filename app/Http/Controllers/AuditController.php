<?php

namespace App\Http\Controllers;

use App\Jobs\RunTrackingAuditJob;
use App\Models\Client;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function index(Client $client)
    {
        return Inertia::render('clients/audit', [
            'client' => app(ClientController::class)->clientProps($client),
            'audit' => $client->audits()->latest()->first(),
            'history' => $client->audits()->latest()->limit(10)->get(['id', 'status', 'score', 'created_at']),
        ]);
    }

    public function store(Client $client)
    {
        if ($client->is_demo) {
            return back()->with('error', 'Klient demo ma przykładowy audyt – dodaj prawdziwego klienta, aby przeskanować stronę.');
        }
        $audit = $client->audits()->create(['url' => $client->website_url]);
        RunTrackingAuditJob::dispatch($audit);

        return back()->with('success', 'Audyt uruchomiony – wyniki pojawią się za ok. 1 minutę.');
    }
}
