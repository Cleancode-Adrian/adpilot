<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Connectors\GoogleAuth;
use App\Services\Connectors\MetaConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Stan systemu: co jest skonfigurowane/połączone, a co wymaga uwagi. */
class SystemStatus
{
    public const HEARTBEAT_KEY = 'adpilot.scheduler.heartbeat';
    public const WORKER_KEY = 'adpilot.worker.heartbeat';

    public function checks(): array
    {
        $c = [];
        $add = function (string $group, string $label, string $status, string $details, ?string $fix = null) use (&$c) {
            $c[] = compact('group', 'label', 'status', 'details', 'fix');
        };

        // --- Serwer
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('Serwer', 'Adres aplikacji (APP_URL)', $https ? 'ok' : (app()->isProduction() ? 'fail' : 'warn'), config('app.url'),
            'Na serwerze ustaw APP_URL=https://twoja-domena.pl – od tego zależą linki w raportach i adresy OAuth.');
        $add('Serwer', 'Tryb produkcyjny', app()->isProduction() && ! config('app.debug') ? 'ok' : 'warn',
            'APP_ENV='.app()->environment().', APP_DEBUG='.(config('app.debug') ? 'true' : 'false'),
            'Na serwerze: APP_ENV=production i APP_DEBUG=false (inaczej błędy pokażą dane konfiguracyjne).');
        $add('Serwer', 'Baza danych', 'ok', config('database.default').' – '.Client::count().' klientów');

        $beat = Cache::get(self::HEARTBEAT_KEY);
        $add('Serwer', 'Harmonogram (cron)', $beat && now()->diffInMinutes($beat) < 5 ? 'ok' : 'fail',
            $beat ? 'Ostatnie uruchomienie: '.$beat->diffForHumans() : 'Nigdy nie uruchomiony',
            'Uruchom "php artisan schedule:work" (Docker: kontener scheduler) albo cron "* * * * * php artisan schedule:run".');

        $worker = Cache::get(self::WORKER_KEY);
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $workerOk = $worker && now()->diffInMinutes($worker) < 15;
        $add('Serwer', 'Kolejka (worker)', $workerOk ? ($failed ? 'warn' : 'ok') : 'fail',
            ($worker ? 'Ostatnie zadanie: '.$worker->diffForHumans() : 'Brak sygnału z workera')."; w kolejce: $pending, nieudane: $failed",
            $workerOk ? ($failed ? 'Sprawdź nieudane zadania: php artisan queue:failed' : null) : 'Uruchom "php artisan queue:work" (Docker: kontener worker). Bez tego nie działają audyty, synchronizacja i raporty.');

        $last = collect(File::glob(storage_path('app/backups/*')))->sort()->last();
        $add('Serwer', 'Kopia zapasowa bazy', $last && now()->diffInHours(\Carbon\Carbon::createFromTimestamp(filemtime($last))) < 30 ? 'ok' : 'warn',
            $last ? basename($last).' ('.round(filesize($last) / 1024).' KB, '.\Carbon\Carbon::createFromTimestamp(filemtime($last))->diffForHumans().')' : 'Brak kopii',
            'Kopia robi się codziennie o 03:00 (php artisan adpilot:backup). Warto ją dodatkowo kopiować poza serwer.');

        // --- Poczta
        $mailer = config('mail.default');
        $add('Poczta', 'Wysyłka e-maili', in_array($mailer, ['log', 'array'], true) ? 'warn' : 'ok', "MAIL_MAILER=$mailer, nadawca: ".config('mail.from.address'),
            'Ustaw SMTP (MAIL_MAILER=smtp, MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS). Teraz maile trafiają tylko do logu.');

        // --- Zewnętrzne usługi
        $add('Google', 'Aplikacja OAuth Google', GoogleAuth::configured() ? 'ok' : 'fail',
            GoogleAuth::configured() ? 'GOOGLE_CLIENT_ID ustawiony' : 'Brak GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET',
            'Google Cloud Console → Dane logowania → Identyfikator klienta OAuth (aplikacja internetowa).');
        $add('Google', 'Developer token Google Ads', config('adpilot.google.ads_developer_token') ? 'ok' : 'fail',
            config('adpilot.google.ads_developer_token') ? 'Ustawiony, API '.config('adpilot.google.ads_api_version') : 'Brak GOOGLE_ADS_DEVELOPER_TOKEN',
            'Konto MCC → Narzędzia → Centrum API → wniosek o Basic access.');
        $add('Google', 'Konto MCC (login-customer-id)', config('adpilot.google.ads_login_customer_id') ? 'ok' : 'warn',
            config('adpilot.google.ads_login_customer_id') ?: 'Nie ustawiono', 'Wpisz ID konta menedżera bez myślników do GOOGLE_ADS_LOGIN_CUSTOMER_ID.');
        $add('Meta', 'Aplikacja Meta', MetaConnector::configured() ? 'ok' : 'fail',
            MetaConnector::configured() ? 'META_APP_ID ustawiony, Graph '.config('adpilot.meta.graph_version') : 'Brak META_APP_ID / META_APP_SECRET',
            'developers.facebook.com → aplikacja typu Firma → Marketing API + Facebook Login for Business.');
        $add('AI', 'Komentarze AI (Anthropic)', config('adpilot.ai.api_key') ? 'ok' : 'warn',
            config('adpilot.ai.api_key') ? 'Model: '.config('adpilot.ai.model') : 'Brak klucza – komentarze z szablonu',
            'Dodaj ANTHROPIC_API_KEY (console.anthropic.com).');
        $add('Inne', 'PageSpeed Insights', config('adpilot.pagespeed.enabled') ? (config('adpilot.pagespeed.api_key') ? 'ok' : 'warn') : 'warn',
            config('adpilot.pagespeed.enabled') ? (config('adpilot.pagespeed.api_key') ? 'Z kluczem API' : 'Bez klucza (niższy limit zapytań)') : 'Wyłączone',
            'Opcjonalnie: klucz PageSpeed z Google Cloud w PAGESPEED_API_KEY.');

        return $c;
    }

    /** Integracje klientów: co połączone, co z błędem, co wygasa. */
    public function integrations(): array
    {
        return Client::active()->with('integrations')->orderBy('name')->get()->map(function (Client $client) {
            $row = ['id' => $client->id, 'name' => $client->name, 'is_demo' => $client->is_demo, 'providers' => []];
            foreach (['google' => 'Google Ads / GA4', 'meta' => 'Meta Ads'] as $p => $label) {
                $i = $client->integration($p);
                if (! $i) {
                    $row['providers'][$p] = ['label' => $label, 'status' => 'none', 'details' => 'Nie połączono'];
                    continue;
                }
                [$status, $details] = match (true) {
                    (bool) $i->last_error => ['fail', $i->last_error],
                    $i->status !== 'connected' => ['warn', 'Połączono, ale nie wybrano konta'],
                    $p === 'meta' && $i->token_expires_at && $i->token_expires_at->lt(now()->addDays(7)) => ['warn', 'Token wygasa '.$i->token_expires_at->diffForHumans()],
                    $i->last_synced_at && $i->last_synced_at->lt(now()->subHours(36)) => ['warn', 'Brak synchronizacji od '.$i->last_synced_at->diffForHumans()],
                    default => ['ok', 'Synchronizacja: '.($i->last_synced_at?->diffForHumans() ?? 'jeszcze nie było')],
                };
                $accounts = collect([
                    $i->setting('customer_id') ? 'Ads '.$i->setting('customer_id') : null,
                    $i->setting('ga4_property_id') ? 'GA4 '.$i->setting('ga4_property_id') : null,
                    $i->setting('ad_account_id'),
                ])->filter()->implode(', ');
                $row['providers'][$p] = ['label' => $label, 'status' => $status, 'details' => $details, 'accounts' => $accounts];
            }
            $audit = $client->audits()->where('status', 'done')->latest()->first();
            $row['audit'] = $audit ? ['score' => $audit->score, 'date' => $audit->created_at->toDateString()] : null;

            return $row;
        })->all();
    }

    /** Liczba problemów (do znaczka w menu). */
    public function problemCount(): int
    {
        $sys = collect($this->checks())->where('status', 'fail')->count();
        $int = collect($this->integrations())->sum(fn ($c) => collect($c['providers'])->whereIn('status', ['fail', 'warn'])->count());

        return $sys + $int;
    }
}
