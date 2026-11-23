<?php

namespace App\Services\WebAudit;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Sprawdzenie zgód w prawdziwej przeglądarce (Chrome/Chromium bez okna, sterowany skryptem resources/node/consent-check.mjs):
 * strona jest otwierana z czystym profilem, nic nie jest klikane w banerze, a my patrzymy, które narzędzia śledzące
 * wysłały dane albo zapisały ciasteczka. To rozstrzyga przypadki, których nie widać w samym kodzie (tagi w Tag Managerze).
 *
 * Wymaga na serwerze: Chrome lub Chromium oraz BROWSER_CHECK=true w .env. Bez tego audyt działa jak dotąd (ocena z kodu).
 */
class BrowserConsentCheck
{
    /** narzędzie => [zapytanie wysyłające dane, ciasteczko narzędzia, parametr „zgoda odmówiona” w Consent Mode (wtedy to tylko sygnał bez ciasteczek)] */
    private const TRACKERS = [
        'Google Analytics 4' => ['~google-analytics\.com/(?:g/|j/)?collect|analytics\.google\.com/g/collect~i', '/^(_ga|_gid|_gat)/', '/[?&]gcs=G1\d0/'],
        'Google Ads' => ['~(?:googleads\.g\.doubleclick\.net|googleadservices\.com|google\.[a-z.]+)/pagead/(?:viewthroughconversion|1p-user-list|1p-conversion|conversion|landing)~i', '/^(_gcl_|IDE$)/', '/[?&]gcs=G10\d/'],
        'Meta Pixel (Facebook)' => ['~facebook\.com/tr[/?]~i', '/^(_fbp|_fbc)/', null],
        'TikTok Pixel' => ['~analytics\.tiktok\.com/api~i', '/^_ttp/', null],
        'Hotjar' => ['~(?:in|vc|content|metrics|ws)\.hotjar\.(?:com|io)~i', '/^_hj/', null],
        'Microsoft Clarity' => ['~clarity\.ms/collect~i', '/^(_clck|_clsk)/', null],
        'LinkedIn Insight' => ['~px\.ads\.linkedin\.com~i', '/^(li_sugr|bcookie|UserMatchHistory)/', null],
        'Microsoft Ads (UET)' => ['~bat\.bing\.com/action~i', '/^(_uetsid|_uetvid)/', null],
        'Pinterest Tag' => ['~ct\.pinterest\.com~i', '/^_pin_unauth/', null],
    ];

    private const CHROME_PATHS = ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium'];

    public function available(): bool
    {
        return (bool) config('adpilot.browser_check.enabled') && $this->chrome() !== null && is_file(base_path('node_modules/puppeteer-core/package.json'));
    }

    /**
     * @return array{tools: array<string, array{sent: bool, denied_only: bool, cookies: list<string>, request: ?string}>, banner: ?array, cookies: list<string>}|null
     *                                                                                                                                                                null = sprawdzenie wyłączone albo się nie udało
     */
    public function run(string $url): ?array
    {
        if (! $this->available()) {
            return null;
        }
        try {
            $p = Process::path(base_path())->timeout(75)
                ->run([(string) config('adpilot.browser_check.node', 'node'), base_path('resources/node/consent-check.mjs'), $url, $this->chrome()]);
            $data = json_decode(trim($p->output()), true);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if (! is_array($data) || empty($data['ok'])) {
            return null;
        }

        return $this->classify($data);
    }

    /** Surowy zapis z przeglądarki (zapytania, ciasteczka, baner) → co każde narzędzie zrobiło przed zgodą. */
    public function classify(array $data): array
    {
        $tools = [];
        $cookieNames = array_values(array_unique(array_column($data['cookies'] ?? [], 'name')));
        foreach (self::TRACKERS as $name => [$reqRe, $cookieRe, $deniedRe]) {
            $requests = array_values(array_filter($data['requests'] ?? [], fn ($u) => preg_match($reqRe, $u)));
            $real = $deniedRe ? array_values(array_filter($requests, fn ($u) => ! preg_match($deniedRe, $u))) : $requests;
            $cookies = array_values(array_filter($cookieNames, fn ($c) => preg_match($cookieRe, $c)));
            if (! $requests && ! $cookies) {
                continue;
            }
            $tools[$name] = [
                'sent' => (bool) ($real || $cookies),
                'denied_only' => $requests && ! $real && ! $cookies, // same sygnały Consent Mode z odmową – bez ciasteczek
                'cookies' => $cookies,
                'request' => $real ? mb_strimwidth(preg_replace('/\?.*$/', '?…', $real[0]), 0, 110, '…') : null,
            ];
        }

        return ['tools' => $tools, 'banner' => $data['banner'] ?? null, 'cookies' => $cookieNames];
    }

    private function chrome(): ?string
    {
        if ($path = config('adpilot.browser_check.chrome')) {
            return is_file($path) ? $path : null;
        }
        foreach (self::CHROME_PATHS as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
