<?php

namespace App\Services\Monitoring;

use App\Mail\ClientAlertMail;
use App\Models\Client;
use App\Models\SiteMonitor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Dostępność stron klientów (co 5 min) i ważność certyfikatów SSL (raz dziennie).
 * Awaria = brak połączenia, timeout, błąd 5xx albo 404/410 strony głównej – potwierdzona drugim sprawdzeniem.
 */
class UptimeMonitor
{
    public const TIMEOUT = 15;

    /** Monitoring włącza się sam dla klientów z opieką lub obsługą ogólną. */
    public function ensureMonitors(): void
    {
        Client::active()->where('is_demo', false)
            ->where(fn ($q) => $q->where('has_care', true)->orWhere('has_general', true))
            ->whereDoesntHave('siteMonitor')->get()
            ->each(fn (Client $c) => $c->siteMonitor()->create(['url' => $c->website_url]));
    }

    public function checkAll(): array
    {
        $this->ensureMonitors();
        return $this->checkMonitors(SiteMonitor::where('enabled', true)->whereHas('client', fn ($q) => $q->active())->get());
    }

    /** @param \Illuminate\Support\Collection<int, SiteMonitor> $monitors */
    public function checkMonitors(\Illuminate\Support\Collection $monitors): array
    {
        if ($monitors->isEmpty()) {
            return [];
        }

        $results = $this->probe($monitors->pluck('url', 'id')->all());
        // potwierdzenie awarii po krótkiej przerwie (pojedyncze „mrugnięcia” serwera to nie awaria)
        $failed = array_keys(array_filter($results, fn ($r) => ! $r['ok']));
        if ($failed && app()->environment() !== 'testing') {
            sleep(20);
        }
        if ($failed) {
            $results = array_replace($results, $this->probe($monitors->whereIn('id', $failed)->pluck('url', 'id')->all()));
        }

        foreach ($monitors as $m) {
            $this->record($m, $results[$m->id]);
        }

        return $results;
    }

    /** @param array<int,string> $urls  id => url */
    private function probe(array $urls): array
    {
        $started = [];
        $responses = Http::pool(function (Pool $pool) use ($urls, &$started) {
            foreach ($urls as $id => $url) {
                $started[$id] = microtime(true);
                $pool->as((string) $id)->withUserAgent('CC-Monitor/1.0 (+uptime)')->timeout(self::TIMEOUT)->connectTimeout(10)
                    ->withOptions(['allow_redirects' => ['max' => 5]])->get($url);
            }
        });

        $out = [];
        foreach ($urls as $id => $url) {
            $res = $responses[(string) $id] ?? null;
            $ms = (int) round((microtime(true) - $started[$id]) * 1000);
            if ($res instanceof Response) {
                $code = $res->status();
                $down = $code >= 500 || in_array($code, [404, 410], true);
                $out[$id] = ['ok' => ! $down, 'code' => $code, 'ms' => $ms, 'error' => $down ? "Odpowiedź HTTP $code" : null];
            } else {
                $msg = $res instanceof Throwable ? $res->getMessage() : 'Brak odpowiedzi';
                $out[$id] = ['ok' => false, 'code' => null, 'ms' => null,
                    'error' => $res instanceof ConnectionException && str_contains($msg, 'timed out') ? 'Przekroczony czas odpowiedzi ('.self::TIMEOUT.' s)' : mb_substr($msg, 0, 250)];
            }
        }

        return $out;
    }

    private function record(SiteMonitor $m, array $r): void
    {
        $wasDown = $m->status === 'down';
        $m->fill([
            'last_checked_at' => now(),
            'last_status_code' => $r['code'],
            'last_response_ms' => $r['ms'],
            'last_error' => $r['error'],
            'status' => $r['ok'] ? 'up' : 'down',
        ]);

        $key = ['client_id' => $m->client_id, 'date' => now()->toDateString()];
        DB::table('uptime_daily')->insertOrIgnore($key + ['checks' => 0, 'failures' => 0, 'total_ms' => 0]);
        DB::table('uptime_daily')->where($key)->update([
            'checks' => DB::raw('checks + 1'),
            'failures' => DB::raw('failures + '.($r['ok'] ? 0 : 1)),
            'total_ms' => DB::raw('total_ms + '.(int) ($r['ms'] ?? 0)),
        ]);

        if (! $r['ok'] && ! $wasDown) {
            $m->down_since = now();
            DB::table('uptime_incidents')->insert(['client_id' => $m->client_id, 'started_at' => now(), 'reason' => $r['error'] ?? 'Awaria']);
            $this->alert($m, '🔴 Strona nie działa: '.$m->client->name, [
                '**Adres:** '.$m->url,
                '**Przyczyna:** '.($r['error'] ?? 'brak odpowiedzi'),
                '**Od:** '.now()->format('d.m.Y H:i'),
            ], 'Sprawdź hosting (panel, limity, PHP), ostatnie zmiany na stronie i logi błędów.');
        } elseif ($r['ok'] && $wasDown) {
            $since = $m->down_since;
            $m->down_since = null;
            DB::table('uptime_incidents')->where('client_id', $m->client_id)->whereNull('ended_at')->update(['ended_at' => now()]);
            $this->alert($m, '🟢 Strona znów działa: '.$m->client->name, [
                '**Adres:** '.$m->url,
                '**Przerwa:** '.($since ? $since->diffForHumans(now(), ['parts' => 2, 'syntax' => Carbon::DIFF_ABSOLUTE]) : 'nieznana'),
                '**Czas odpowiedzi:** '.$r['ms'].' ms',
            ]);
        }
        $m->save();
    }

    /** Certyfikat SSL: data ważności i wystawca. Ostrzeżenie 14 dni przed wygaśnięciem, potem co 3 dni. */
    public function checkSsl(SiteMonitor $m): void
    {
        $host = parse_url($m->url, PHP_URL_HOST);
        if (! $host || parse_url($m->url, PHP_URL_SCHEME) !== 'https') {
            $m->update(['ssl_error' => 'Strona nie używa HTTPS', 'ssl_checked_at' => now(), 'ssl_expires_at' => null]);

            return;
        }
        $cert = $this->fetchCertificate($host);
        if (! $cert) {
            $m->update(['ssl_error' => 'Nie udało się pobrać certyfikatu', 'ssl_checked_at' => now()]);

            return;
        }
        $m->update([
            'ssl_expires_at' => Carbon::createFromTimestamp($cert['validTo_time_t']),
            'ssl_issuer' => mb_substr($cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '', 0, 190),
            'ssl_error' => null,
            'ssl_checked_at' => now(),
        ]);

        $days = $m->sslDaysLeft();
        if ($days !== null && $days <= 14 && (! $m->ssl_alerted_at || $m->ssl_alerted_at->lt(now()->subDays(3)))) {
            $this->alert($m, ($days < 0 ? '🔴 Certyfikat SSL wygasł: ' : '🟠 Certyfikat SSL wygasa za '.$days.' dni: ').$m->client->name, [
                '**Domena:** '.$host,
                '**Ważny do:** '.$m->ssl_expires_at->format('d.m.Y'),
                '**Wystawca:** '.($m->ssl_issuer ?: '–'),
            ], 'Odnów certyfikat w panelu hostingu (Let\'s Encrypt zwykle odnawia się sam – jeśli nie, sprawdź DNS i ustawienia SSL domeny).');
            $m->update(['ssl_alerted_at' => now()]);
        }
    }

    /** Osobna metoda – w testach podmieniana. */
    protected function fetchCertificate(string $host): ?array
    {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client("ssl://$host:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (! $client) {
            return null;
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        return $cert ? (openssl_x509_parse($cert) ?: null) : null;
    }

    private function alert(SiteMonitor $m, string $title, array $lines, ?string $action = null): void
    {
        try {
            foreach (User::where('is_admin', true)->pluck('email') as $email) {
                Mail::to($email)->send(new ClientAlertMail($title, $lines, route('clients.show', $m->client_id), $action));
            }
        } catch (Throwable $e) {
            Log::warning('Uptime alert mail failed: '.$e->getMessage());
        }
    }

    /** Dostępność w % z ostatnich N dni (null = brak pomiarów). */
    public static function uptimePercent(int $clientId, int $days = 30): ?float
    {
        $r = DB::table('uptime_daily')->where('client_id', $clientId)->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->selectRaw('SUM(checks) as c, SUM(failures) as f, SUM(total_ms) as ms')->first();

        return $r && $r->c ? round(100 - $r->f / $r->c * 100, 2) : null;
    }
}
