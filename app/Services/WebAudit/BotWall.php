<?php

namespace App\Services\WebAudit;

use Illuminate\Http\Client\Response;

/**
 * Rozpoznaje strony „weryfikacji” zabezpieczeń (Cloudflare, SiteGround, Imunify360, ochrona hostingu, limity zapytań),
 * które serwer pokazuje automatom zamiast właściwej treści. Taka strona NIE jest treścią witryny – nie wolno jej
 * oceniać (tytuł, nagłówki, teksty, pomiar), bo raport opisywałby ekran zabezpieczenia jako błąd strony.
 */
class BotWall
{
    private const SIGNATURES = [
        'Cloudflare' => ['cf-browser-verification', 'challenge-platform', '_cf_chl_', 'cf_chl_opt', 'Attention Required! | Cloudflare', '<title>Just a moment...</title>'],
        'SiteGround' => ['sgcaptcha', '/.well-known/sgcaptcha'],
        'Imunify360' => ['imunify360', 'Imunify360 bot-protection', 'bot-protection'],
        'Sucuri' => ['sucuri_cloudproxy', 'Sucuri WebSite Firewall', 'Access Denied - Sucuri'],
        'DDoS-Guard' => ['ddos-guard', 'DDoS-Guard'],
        'Wordfence' => ['Wordfence', 'wfwaf-'],
        'zabezpieczenie hostingu' => ['Please wait while your request is being verified', 'One moment, please...', 'Checking your browser before accessing',
            'Your request is being verified', 'Verifying you are human', 'Please enable JavaScript and cookies to continue', 'Trwa weryfikacja', 'Sprawdzanie przeglądarki',
            '/__nodea/', '<title>Weryfikacja', '<title>Verification', '<title>Security check', '<title>Checking your browser'],
    ];

    /** Nazwa zabezpieczenia albo null, gdy to zwykła strona. */
    public static function detect(string $body, int $status = 200): ?string
    {
        // Prawdziwe strony bywają duże; ekrany weryfikacji są małe. Duża strona z kodem 200 to treść, nawet jeśli wspomina np. „Wordfence”.
        $small = strlen($body) < 40_000;
        if (! $small && $status < 400 && $status !== 202) {
            return null;
        }
        foreach (self::SIGNATURES as $name => $needles) {
            foreach ($needles as $n) {
                if (stripos($body, $n) !== false) {
                    // „Wordfence”/„bot-protection” mogą być w zwykłym kodzie – liczą się tylko przy kodach błędów
                    if (in_array($name, ['Wordfence', 'Imunify360'], true) && $status < 400 && $status !== 202 && ! str_contains(strtolower($body), 'captcha')) {
                        continue;
                    }

                    return $name;
                }
            }
        }
        if (in_array($status, [429, 503], true) && $small) {
            return 'limit zapytań serwera';
        }

        return null;
    }

    public static function detectResponse(Response $r): ?string
    {
        return self::detect($r->body(), $r->status());
    }
}
