<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSearchConsoleJob;
use App\Models\Client;
use App\Services\Connectors\GoogleAuth;
use App\Services\Connectors\SearchConsoleConnector;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Zakładka SEO klienta: Google Search Console – kliknięcia, wyświetlenia, pozycje, frazy i podstrony. */
class SeoController extends Controller
{
    public function index(Client $client)
    {
        $google = $client->integration('google') ?? $client->integrations()->where('provider', 'google')->first();
        $site = $google?->setting('gsc_site');
        $rows = DB::table('search_console_rows')->where('client_id', $client->id)->orderByDesc('clicks')->orderByDesc('impressions')->get();

        return Inertia::render('clients/seo', [
            'client' => app(ClientController::class)->clientProps($client),
            'google_configured' => GoogleAuth::configured(),
            'connected' => (bool) $google?->refresh_token,
            'site' => $site,
            'sites' => $google?->available_accounts['gsc'] ?? [],
            'last_synced_at' => $google?->last_synced_at,
            'last_error' => $google?->last_error,
            'summary' => $site ? SearchConsoleConnector::summary($client) : null,
            'daily' => $site ? DB::table('search_console_daily')->where('client_id', $client->id)->orderBy('date')->get(['date', 'clicks', 'impressions', 'position']) : [],
            'queries' => $rows->where('type', 'query')->values(),
            'pages' => $rows->where('type', 'page')->values(),
        ]);
    }

    public function sync(Client $client)
    {
        abort_unless($client->integration('google')?->setting('gsc_site'), 422, 'Najpierw wybierz witrynę z Search Console.');
        SyncSearchConsoleJob::dispatch($client);

        return back()->with('success', 'Pobieranie danych z Search Console ruszyło – odśwież stronę za minutę.');
    }
}
