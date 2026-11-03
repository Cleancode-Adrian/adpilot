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
    /** Lista klientów – punkt wyjścia: dane, logowanie do strony, opieka, reklamy. */
    public function index(Request $request)
    {
        $archived = $request->boolean('archived');
        $planner = app(\App\Services\Care\CarePlanner::class);

        $clients = Client::query()
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->active())
            ->withCount(['credentials', 'recommendations as open_count' => fn ($q) => $q->open()])
            ->orderBy('is_demo')->orderBy('name')->get()
            ->map(fn (Client $c) => [
                ...$c->only(['id', 'name', 'website_url', 'admin_url', 'industry', 'has_ads', 'has_care', 'has_general', 'contact_name', 'contact_email', 'contact_phone', 'is_demo', 'archived_at']),
                'notes_excerpt' => $c->notes ? \Illuminate\Support\Str::limit($c->notes, 140) : null,
                'credentials_count' => $c->credentials_count,
                'open_count' => $c->has_ads ? $c->open_count : 0,
                'care' => $c->has_care || $c->has_general ? $planner->summary($c) : null,
                'wp_alerts' => $c->wpEvents()->alerts()->count(),
                'site_down' => $c->siteMonitor?->enabled && $c->siteMonitor->status === 'down',
                'ssl_days' => $c->siteMonitor?->sslDaysLeft(),
                'wp_connected' => $c->wpSite?->status === 'connected',
            ]);

        return Inertia::render('clients/index', [
            'clients' => $clients,
            'showArchived' => $archived,
            'archivedCount' => Client::whereNotNull('archived_at')->count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('clients/form', ['client' => null]);
    }

    public function store(Request $request)
    {
        $client = Client::create($this->validated($request));
        $this->syncPanelLogin($request, $client);

        if ($client->has_ads) {
            // od razu audyt strony – działa bez dostępu do kont reklamowych
            app(AuditController::class)->store($client);

            return redirect()->route('clients.integrations', $client)->with('success', 'Klient dodany. Audyt strony został uruchomiony – teraz podłącz konta reklamowe.');
        }
        if ($client->has_care) {
            return redirect()->route('clients.care', $client)->with('success', 'Klient dodany. Wybierz pakiet opieki albo dodaj własne zadania.');
        }

        return redirect()->route('clients.show', $client)->with('success', 'Klient dodany.');
    }

    public function edit(Request $request, Client $client)
    {
        $panel = $request->user()->is_admin ? $this->panelCredential($client) : null;

        return Inertia::render('clients/form', ['client' => $client, 'panel' => $panel ? ['username' => $panel->username, 'has_secret' => filled($panel->getRawOriginal('secret'))] : null]);
    }

    public function update(Request $request, Client $client)
    {
        $client->update($this->validated($request));
        $this->syncPanelLogin($request, $client);

        return redirect()->route('clients.show', $client)->with('success', 'Zapisano zmiany.');
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return redirect()->route('clients.index')->with('success', 'Klient usunięty.');
    }

    /** Karta klienta: dane, kontakt, logowanie do strony, notatki, skrót opieki i reklam. */
    public function show(Client $client)
    {
        $period = \App\Services\Care\CarePlanner::period();
        if ($client->has_care) {
            app(\App\Services\Care\CarePlanner::class)->ensureMonth($client);
        }

        return Inertia::render('clients/card', [
            'client' => $this->clientProps($client),
            'upcoming' => $client->careTasks()->todo()->where('period', '<=', $period)->orderBy('due_date')->limit(8)
                ->get(['id', 'care_template_id', 'period', 'title', 'description', 'due_date', 'status', 'done_at', 'note', 'minutes']),
            'adsKpis' => $client->has_ads ? (new Metrics($client))->kpis(CarbonImmutable::yesterday()->subDays(29), CarbonImmutable::yesterday()) : null,
        ]);
    }

    /** Notatki z karty klienta (zapis w locie). */
    public function updateNotes(Request $request, Client $client)
    {
        $client->update($request->validate(['notes' => 'nullable|string|max:20000']));

        return back();
    }

    /** Wyniki reklam: KPI, wykres, kampanie, reklamy. */
    public function ads(Request $request, Client $client)
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
            'funnel' => $m->funnel($from, $to),
            'behavior' => $m->behavior($from, $to),
            'clarityDetected' => in_array('Microsoft Clarity', (array) ($client->audits()->where('status', 'done')->latest()->first()?->results['detected']['others'] ?? []), true),
            'today' => ($today = $m->totals(now()->startOfDay(), now()->startOfDay()))['impressions'] > 0 ? [
                ...$today,
                'meta' => $m->totals(now()->startOfDay(), now()->startOfDay(), 'meta_ads'),
                'google' => $m->totals(now()->startOfDay(), now()->startOfDay(), 'google_ads'),
                'synced_at' => $client->integrations()->max('last_synced_at'),
            ] : null,
            'placements' => $m->placements($from, $to)->filter(fn ($p) => $p['impressions'] > 0)->values(),
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

        return redirect()->route($archived ? 'clients.index' : 'clients.show', $archived ? [] : $client)
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
            ...$client->only(['id', 'name', 'website_url', 'has_ads', 'has_care', 'has_general', 'admin_url', 'contact_name', 'contact_phone', 'industry', 'goal', 'lead_events', 'currency', 'target_roas', 'target_cpa', 'monthly_budget', 'daily_budget', 'contact_email', 'auto_send_reports', 'notes', 'is_demo', 'archived_at']),
            'open_count' => $client->recommendations()->open()->count(),
            'integrations' => $client->integrations->map->only(['id', 'provider', 'status', 'settings', 'last_synced_at', 'last_error']),
            'lead_event_names' => $client->leadEventNames(),
            'google_configured' => GoogleAuth::configured(),
            'meta_configured' => MetaConnector::configured(),
            'care' => $client->careTemplates()->exists() || $client->careTasks()->exists() ? app(\App\Services\Care\CarePlanner::class)->summary($client) : null,
            'credentials_count' => $client->credentials()->count(),
            'site' => ($mon = $client->siteMonitor) ? [
                'status' => $mon->enabled ? $mon->status : 'off',
                'ssl_days' => $mon->sslDaysLeft(),
                'last_response_ms' => $mon->last_response_ms,
                'audit_score' => $client->siteAudits()->where('status', 'done')->latest('id')->value('score'),
            ] : ['status' => null, 'ssl_days' => null, 'last_response_ms' => null, 'audit_score' => $client->siteAudits()->where('status', 'done')->latest('id')->value('score')],
            'wp' => ($wp = $client->wpSite) ? [
                'status' => $wp->status,
                'alerts' => $client->wpEvents()->alerts()->count(),
                'critical' => $client->wpEvents()->alerts()->where('severity', 'critical')->count(),
                'updates' => collect($wp->report['plugins'] ?? [])->whereNotNull('update')->count() + collect($wp->report['themes'] ?? [])->whereNotNull('update')->count() + (empty($wp->report['wp_update']) ? 0 : 1),
            ] : null,
            // Logowanie do panelu strony – bez hasła (to odsłania się osobno, z wpisem w dzienniku)
            'panel' => auth()->user()?->is_admin && ($p = $this->panelCredential($client))
                ? ['id' => $p->id, 'username' => $p->username, 'url' => $p->url, 'has_secret' => filled($p->getRawOriginal('secret'))]
                : null,
        ];
    }

    /** Pierwszy zapisany dostęp do panelu strony (kategoria „cms”). */
    private function panelCredential(Client $client): ?\App\Models\ClientCredential
    {
        return $client->credentials()->where('category', 'cms')->orderBy('id')->first();
    }

    /** Login i hasło do panelu z formularza klienta → zaszyfrowany sejf (tylko administrator). */
    private function syncPanelLogin(Request $request, Client $client): void
    {
        if (! $request->user()?->is_admin) {
            return;
        }
        $data = $request->validate(['panel_login' => 'nullable|string|max:255', 'panel_password' => 'nullable|string|max:2000']);
        $login = $data['panel_login'] ?? null;
        $password = $data['panel_password'] ?? null;
        $cred = $this->panelCredential($client);

        if (! $cred && ! filled($login) && ! filled($password)) {
            return;
        }
        $cred ??= $client->credentials()->make(['category' => 'cms', 'label' => 'Panel strony']);
        $cred->fill(['username' => $login, 'url' => $client->admin_url ?? $cred->url]);
        if (filled($password) && $password !== $cred->secret) {
            $cred->fill(['secret' => $password, 'secret_changed_at' => now()]);
        }
        if ($cred->isDirty() || ! $cred->exists) {
            $action = $cred->exists ? 'update' : 'create';
            $cred->save();
            $cred->log($action, $request->user(), $request->ip());
        }
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
            'has_ads' => 'boolean',
            'has_care' => 'boolean',
            'has_general' => 'boolean',
            'admin_url' => 'nullable|string|max:500',
            'contact_name' => 'nullable|string|max:150',
            'contact_phone' => 'nullable|string|max:50',
            'industry' => 'nullable|string|max:150',
            'goal' => 'nullable|in:sales,leads,mixed',
            'lead_events' => 'nullable|string|max:500',
            'currency' => 'nullable|string|size:3',
            'target_roas' => 'nullable|numeric|min:0|max:100',
            'target_cpa' => 'nullable|numeric|min:0',
            'monthly_budget' => 'nullable|numeric|min:0',
            'daily_budget' => 'nullable|numeric|min:0',
            'contact_email' => 'nullable|email',
            'auto_send_reports' => 'boolean',
            'notes' => 'nullable|string|max:20000',
        ]);
        $url = Client::normalizeUrl($data['website_url']);
        if (! $url) {
            throw \Illuminate\Validation\ValidationException::withMessages(['website_url' => 'Nieprawidłowy adres strony – wpisz np. projekciarz.pl lub https://projekciarz.pl']);
        }
        $data['website_url'] = $url;
        $data['currency'] = strtoupper($data['currency'] ?? 'PLN');
        if (filled($data['admin_url'] ?? null) && ! preg_match('#^https?://#i', $data['admin_url'])) {
            $data['admin_url'] = 'https://'.ltrim($data['admin_url'], '/');
        }
        $data['goal'] ??= 'sales';
        $events = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string) ($data['lead_events'] ?? '')))));
        $data['lead_events'] = $events ?: null;

        return $data;
    }
}
