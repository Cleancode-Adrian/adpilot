<?php

namespace App\Http\Controllers;

use App\Jobs\PullWpSiteJob;
use App\Models\Client;
use App\Models\WpEvent;
use App\Models\WpSite;
use App\Services\WordPress\WpMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

/** Zakładka „WordPress” klienta + odbiór danych z wtyczki CC Connector. */
class WordPressController extends Controller
{
    public function __construct(private WpMonitor $monitor) {}

    public function index(Request $request, Client $client)
    {
        $site = $client->wpSite;
        $report = $site?->report ?? [];
        $plugins = collect($report['plugins'] ?? []);
        $themes = collect($report['themes'] ?? []);
        $filter = $request->input('events', 'alerts');

        return Inertia::render('clients/wordpress', [
            'client' => app(ClientController::class)->clientProps($client),
            'site' => $site ? [
                ...$site->only(['id', 'status', 'last_seen_at', 'last_report_at', 'last_pull_at', 'last_error', 'scan']),
                'report' => $report ?: null,
                'php' => WpMonitor::phpStatus($report['php_version'] ?? null),
                'plugin_updates' => $plugins->whereNotNull('update')->values(),
                'theme_updates' => $themes->whereNotNull('update')->values(),
            ] : null,
            'connectionKey' => $site && $request->user()->is_admin ? $site->connectionKey() : null,
            'events' => $site ? $client->wpEvents()
                ->when($filter === 'alerts', fn ($q) => $q->alerts())
                ->when($filter === 'all', fn ($q) => $q)
                ->latest('occurred_at')->latest('id')->limit(200)->get() : [],
            'eventFilter' => $filter,
            'alertCount' => $client->wpEvents()->alerts()->count(),
        ]);
    }

    public function connect(Client $client)
    {
        $client->wpSite ?? WpSite::generateFor($client);

        return back()->with('success', 'Wygenerowano klucz. Pobierz plik wtyczki i wgraj go na stronę.');
    }

    /** Nowy sekret – stary plik/klucz przestaje działać (np. gdy plik trafił w niepowołane ręce). */
    public function regenerate(Client $client)
    {
        $site = $client->wpSite ?? abort(404);
        $site->update(['secret' => Str::random(64), 'status' => 'pending']);

        return back()->with('success', 'Wygenerowano nowy klucz. Stary przestał działać – wgraj na stronę nowy plik wtyczki.');
    }

    public function destroy(Client $client)
    {
        $client->wpSite?->delete();

        return back()->with('success', 'Odłączono stronę. Usuń plik wtyczki z serwera klienta (wp-content/mu-plugins/cc-connector.php).');
    }

    public function pull(Client $client)
    {
        $site = $client->wpSite ?? abort(404);
        PullWpSiteJob::dispatch($site, true);

        return back()->with('success', 'Sprawdzam stronę (raport + skan plików). Odśwież za ok. minutę.');
    }

    /** Wtyczka do pobrania: mu-plugin z wbudowanym kluczem albo zwykła wtyczka ZIP (klucz w ustawieniach). */
    public function download(Request $request, Client $client)
    {
        $site = $client->wpSite ?? WpSite::generateFor($client);
        $source = file_get_contents(resource_path('wordpress/cc-connector/cc-connector.php'));

        if ($request->query('type') === 'zip' && class_exists(\ZipArchive::class)) {
            $tmp = tempnam(sys_get_temp_dir(), 'ccz');
            $zip = new \ZipArchive;
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $zip->addFromString('cc-connector/cc-connector.php', $source);
            $zip->close();

            return response()->download($tmp, 'cc-connector.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
        }

        $embedded = str_replace("const EMBEDDED_KEY = '';", "const EMBEDDED_KEY = '".$site->connectionKey()."';", $source);

        return response($embedded, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="cc-connector.php"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function acknowledge(Request $request, WpEvent $event)
    {
        $event->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);

        return back();
    }

    public function acknowledgeAll(Request $request, Client $client)
    {
        $n = $client->wpEvents()->whereNull('acknowledged_at')->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);

        return back()->with('success', "Oznaczono $n zdarzeń jako sprawdzone.");
    }

    // ------------------------------------------------------------------ API dla wtyczki

    public function push(Request $request)
    {
        if (strlen($request->getContent()) > 3_000_000) {
            return response()->json(['message' => 'Za duże dane.'], 413);
        }
        try {
            $site = $this->monitor->verify($request);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
        if ($site->client?->archived_at) {
            return response()->json(['message' => 'Klient zarchiwizowany.'], 410);
        }
        try {
            $this->monitor->ingest($site, (array) $request->json()->all());
        } catch (Throwable $e) {
            Log::warning('WP push failed', ['site' => $site->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Nieprawidłowe dane.'], 422);
        }

        return response()->json(['ok' => true]);
    }
}
