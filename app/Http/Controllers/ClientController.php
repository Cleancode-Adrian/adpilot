<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientJob;
use App\Models\Client;
use App\Services\Analysis\Metrics;
use App\Services\Connectors\GoogleAuth;
use App\Services\Connectors\MetaConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index()
    {
        return redirect()->route('dashboard');
    }

    public function create()
    {
        return Inertia::render('clients/form', ['client' => null]);
    }

    public function store(Request $request)
    {
        $client = Client::create($this->validated($request));
        // od razu audyt strony – działa bez dostępu do kont reklamowych
        app(AuditController::class)->store($client);

        return redirect()->route('clients.integrations', $client)->with('success', 'Klient dodany. Audyt strony został uruchomiony – teraz podłącz konta reklamowe.');
    }

    public function edit(Client $client)
    {
        return Inertia::render('clients/form', ['client' => $client]);
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->validated($request));

        return redirect()->route('clients.show', $client)->with('success', 'Zapisano zmiany.');
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()->route('dashboard')->with('success', 'Klient usunięty.');
    }

    /** Przegląd: KPI, wykres, kampanie, reklamy. */
    public function show(Request $request, Client $client)
    {
        [$from, $to, $days] = $this->period($request);
        $m = new Metrics($client);

        return Inertia::render('clients/show', [
            'client' => $this->clientProps($client),
            'days' => $days,
            'kpis' => $m->kpis($from, $to),
            'daily' => $m->daily($from, $to),
            'campaigns' => $m->campaigns($from, $to),
            'ads' => $m->ads($from, $to)->take(100)->values(),
            'channels' => $m->channels($from, $to),
            'searchTerms' => $client->searchTerms()->with('campaign:id,name')->orderByDesc('cost')->limit(50)->get(),
        ]);
    }

    public function recommendations(Request $request, Client $client)
    {
        $status = $request->input('status', 'open');

        return Inertia::render('clients/recommendations', [
            'client' => $this->clientProps($client),
            'status' => $status,
            'recommendations' => $client->recommendations()->where('status', $status)->bySeverity()->get(),
            'counts' => $client->recommendations()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'plan' => $client->reports()->where('type', 'internal')->latest()->first(),
        ]);
    }

    public function archive(Client $client)
    {
        $archived = ! $client->archived_at;
        $client->update(['archived_at' => $archived ? now() : null]);

        return redirect()->route($archived ? 'dashboard' : 'clients.show', $archived ? [] : $client)
            ->with('success', $archived ? "Klient „{$client->name}” zarchiwizowany – nie jest synchronizowany, dane i raporty zostają." : 'Klient przywrócony.');
    }

    public function sync(Client $client)
    {
        SyncClientJob::dispatch($client);

        return back()->with('success', 'Synchronizacja zlecona – dane odświeżą się w ciągu kilku minut.');
    }

    public function clientProps(Client $client): array
    {
        $client->load('integrations');

        return [
            ...$client->only(['id', 'name', 'website_url', 'industry', 'goal', 'lead_events', 'currency', 'target_roas', 'target_cpa', 'monthly_budget', 'daily_budget', 'contact_email', 'auto_send_reports', 'notes', 'is_demo', 'archived_at']),
            'open_count' => $client->recommendations()->open()->count(),
            'integrations' => $client->integrations->map->only(['id', 'provider', 'status', 'settings', 'last_synced_at', 'last_error']),
            'lead_event_names' => $client->leadEventNames(),
            'google_configured' => GoogleAuth::configured(),
            'meta_configured' => MetaConnector::configured(),
        ];
    }

    private function period(Request $request): array
    {
        $days = in_array((int) $request->input('days'), [7, 14, 30, 60, 90], true) ? (int) $request->input('days') : 30;
        $to = CarbonImmutable::yesterday();

        return [$to->subDays($days - 1), $to, $days];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'website_url' => 'required|string|max:255',
            'industry' => 'nullable|string|max:150',
            'goal' => 'nullable|in:sales,leads,mixed',
            'lead_events' => 'nullable|string|max:500',
            'currency' => 'required|string|size:3',
            'target_roas' => 'nullable|numeric|min:0|max:100',
            'target_cpa' => 'nullable|numeric|min:0',
            'monthly_budget' => 'nullable|numeric|min:0',
            'daily_budget' => 'nullable|numeric|min:0',
            'contact_email' => 'nullable|email',
            'auto_send_reports' => 'boolean',
            'notes' => 'nullable|string|max:5000',
        ]);
        $url = Client::normalizeUrl($data['website_url']);
        if (! $url) {
            throw \Illuminate\Validation\ValidationException::withMessages(['website_url' => 'Nieprawidłowy adres strony – wpisz np. projekciarz.pl lub https://projekciarz.pl']);
        }
        $data['website_url'] = $url;
        $data['currency'] = strtoupper($data['currency']);
        $data['goal'] ??= 'sales';
        $events = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string) ($data['lead_events'] ?? '')))));
        $data['lead_events'] = $events ?: null;

        return $data;
    }
}
