<?php

namespace App\Services\WebAudit;

use App\Services\WordPress\WpMonitor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Podstawowe bezpieczeństwo strony – tylko publiczne, nieinwazyjne sprawdzenia (to, co widzi każdy odwiedzający):
 * HTTPS i certyfikat, nagłówki bezpieczeństwa, ujawnione wersje oprogramowania, typowe furtki WordPressa.
 */
class SecurityScanner
{
    private const UA = 'Mozilla/5.0 (compatible; CC-SiteAudit/1.0)';

    public function scan(string $url): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $details, ?string $fix = null, int $weight = 3) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'details', 'fix', 'weight');
        };

        $url = parse_url($url, PHP_URL_PATH) ? $url : rtrim($url, '/').'/'; // adres domeny zawsze z końcowym /
        $res = Http::withUserAgent(self::UA)->timeout(20)->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])->get($url);
        $history = (array) ($res->headers()['X-Guzzle-Redirect-History'] ?? []);
        $final = $history ? end($history) : $url;
        $h = fn (string $name) => trim((string) $res->header($name));
        $html = (string) $res->body();
        $host = (string) parse_url($final, PHP_URL_HOST);

        // HTTPS i certyfikat
        $https = str_starts_with($final, 'https://');
        $add('https', 'Szyfrowane połączenie (HTTPS)', $https ? 'ok' : 'fail', $https ? 'Strona działa przez HTTPS.' : 'Strona działa bez szyfrowania – przeglądarka pokazuje „Niezabezpieczona”.',
            'Włącz certyfikat SSL (Let\'s Encrypt jest darmowy) i przekierowanie na https://.', 10);
        if ($https) {
            $cert = $this->fetchCertificate($host);
            if ($cert) {
                $exp = Carbon::createFromTimestamp($cert['validTo_time_t']);
                $days = (int) floor(now()->diffInDays($exp, false));
                $add('ssl_expiry', 'Ważność certyfikatu SSL', $days < 0 ? 'fail' : ($days <= 14 ? 'warn' : 'ok'),
                    $days < 0 ? 'Certyfikat wygasł '.$exp->format('d.m.Y').'.' : "Ważny do {$exp->format('d.m.Y')} ($days dni).",
                    'Odnów certyfikat i włącz automatyczne odnawianie w panelu hostingu.', 6);
            }
        }

        // Nagłówki bezpieczeństwa
        $csp = $h('Content-Security-Policy');
        $add('hsts', 'Wymuszanie HTTPS (HSTS)', $h('Strict-Transport-Security') ? 'ok' : 'warn', $h('Strict-Transport-Security') ? 'Nagłówek HSTS jest ustawiony.' : 'Brak nagłówka Strict-Transport-Security.',
            'Dodaj nagłówek Strict-Transport-Security (hosting / .htaccess / wtyczka bezpieczeństwa).', 3);
        $add('nosniff', 'Ochrona typu treści (X-Content-Type-Options)', strtolower($h('X-Content-Type-Options')) === 'nosniff' ? 'ok' : 'warn',
            strtolower($h('X-Content-Type-Options')) === 'nosniff' ? 'Ustawione „nosniff”.' : 'Brak nagłówka X-Content-Type-Options: nosniff.', 'Dodaj nagłówek X-Content-Type-Options: nosniff.', 2);
        $frame = $h('X-Frame-Options') || str_contains(strtolower($csp), 'frame-ancestors');
        $add('clickjacking', 'Ochrona przed osadzaniem w ramce (clickjacking)', $frame ? 'ok' : 'warn', $frame ? 'Strony nie da się osadzić na obcej stronie.' : 'Stronę można osadzić w ramce na dowolnej stronie.',
            'Dodaj X-Frame-Options: SAMEORIGIN albo CSP frame-ancestors.', 2);
        $add('csp', 'Polityka treści (Content-Security-Policy)', $csp ? 'ok' : 'warn', $csp ? 'CSP jest ustawione.' : 'Brak CSP – mniejsza ochrona przed wstrzyknięciem skryptów.', 'Rozważ dodanie Content-Security-Policy (najpierw w trybie Report-Only).', 1);
        $add('referrer', 'Polityka odsyłaczy (Referrer-Policy)', $h('Referrer-Policy') ? 'ok' : 'warn', $h('Referrer-Policy') ?: 'Brak nagłówka Referrer-Policy.', 'Dodaj Referrer-Policy: strict-origin-when-cross-origin.', 1);

        // Ujawnione wersje oprogramowania
        $powered = $h('X-Powered-By');
        $server = $h('Server');
        if (preg_match('/php\/?\s*([\d.]+)/i', $powered, $m)) {
            $php = WpMonitor::phpStatus($m[1]);
            $add('php_version', 'Wersja PHP', $php['status'] === 'eol' ? 'fail' : 'warn',
                "Serwer ujawnia PHP {$m[1]}".($php['status'] === 'eol' ? ' – wersja bez poprawek bezpieczeństwa.' : '.'),
                'Ukryj nagłówek X-Powered-By (expose_php = Off)'.($php['status'] === 'eol' ? ' i zaktualizuj PHP do wspieranej wersji.' : '.'), $php['status'] === 'eol' ? 6 : 2);
        } elseif ($powered) {
            $add('powered_by', 'Ujawniona technologia (X-Powered-By)', 'warn', "X-Powered-By: $powered", 'Usuń nagłówek X-Powered-By.', 1);
        }
        if (preg_match('/\d+\.\d+/', $server)) {
            $add('server_version', 'Ujawniona wersja serwera', 'warn', "Server: $server", 'Ukryj wersję serwera (ServerTokens Prod / server_tokens off).', 1);
        }

        // WordPress: typowe publiczne furtki
        $isWp = str_contains($html, '/wp-content/') || str_contains($html, '/wp-includes/');
        if ($isWp) {
            $base = rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $final), '/');
            if (preg_match('/<meta[^>]+generator[^>]+WordPress\s*([\d.]+)/i', $html, $m)) {
                $add('wp_version_public', 'Wersja WordPressa widoczna publicznie', 'warn', "Kod strony podaje: WordPress {$m[1]}.", 'Usuń znacznik generator (wtyczka bezpieczeństwa albo remove_action(\'wp_head\', \'wp_generator\')).', 2);
            }
            $xml = $this->get($base.'/xmlrpc.php');
            $xmlOpen = $xml && (in_array($xml->status(), [200, 405], true) && str_contains($xml->body(), 'XML-RPC'));
            $add('xmlrpc', 'XML-RPC (częsty cel ataków na hasła)', $xmlOpen ? 'warn' : 'ok', $xmlOpen ? 'xmlrpc.php jest dostępny.' : 'xmlrpc.php jest zablokowany.',
                'Zablokuj xmlrpc.php, jeśli nie korzystasz z aplikacji mobilnej WordPress / Jetpacka.', 3);
            $users = $this->get($base.'/wp-json/wp/v2/users');
            $list = $users && $users->successful() ? $users->json() : null;
            $exposed = is_array($list) && $list && isset($list[0]['slug']);
            $add('wp_users', 'Lista użytkowników przez REST API', $exposed ? 'warn' : 'ok', $exposed ? 'Publicznie widać loginy '.count($list).' kont (ułatwia zgadywanie haseł).' : 'Lista użytkowników nie jest publiczna.',
                'Zablokuj endpoint /wp-json/wp/v2/users dla niezalogowanych (wtyczka bezpieczeństwa).', 3);
            $uploads = $this->get($base.'/wp-content/uploads/');
            $listing = $uploads && $uploads->successful() && str_contains($uploads->body(), 'Index of');
            $add('dir_listing', 'Listowanie katalogów', $listing ? 'fail' : 'ok', $listing ? 'Katalog /wp-content/uploads/ pokazuje listę plików każdemu.' : 'Katalogi nie są listowane.',
                'Wyłącz listowanie katalogów (Options -Indexes w .htaccess).', 4);
        }

        return ['score' => $this->score($checks), 'checks' => $checks, 'wordpress' => $isWp];
    }

    private function get(string $url)
    {
        try {
            return Http::withUserAgent(self::UA)->timeout(10)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable) {
            return null;
        }
    }

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

    private function score(array $checks): int
    {
        $total = $earned = 0;
        foreach ($checks as $c) {
            $total += $c['weight'];
            $earned += match ($c['status']) { 'ok' => $c['weight'], 'warn' => $c['weight'] * 0.4, default => 0 };
        }

        return $total ? (int) round($earned / $total * 100) : 0;
    }
}
