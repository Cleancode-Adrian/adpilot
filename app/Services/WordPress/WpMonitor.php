<?php

namespace App\Services\WordPress;

use App\Mail\WpAlertMail;
use App\Models\User;
use App\Models\WpEvent;
use App\Models\WpSite;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Monitoring stron WordPress przez wtyczkę CC Connector.
 * Komunikacja w obie strony podpisana HMAC-SHA256 (sekret znany tylko panelowi i stronie), ważność ±5 min.
 */
class WpMonitor
{
    /** Koniec wsparcia bezpieczeństwa wersji PHP (php.net/supported-versions). */
    public const PHP_EOL = [
        '5.6' => '2018-12-31', '7.0' => '2019-01-10', '7.1' => '2019-12-01', '7.2' => '2020-11-30', '7.3' => '2021-12-06',
        '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31',
        '8.4' => '2028-12-31', '8.5' => '2029-12-31',
    ];

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp."\n".$body, $secret);
    }

    /** Sprawdza podpis zapytania od wtyczki i zwraca stronę. */
    public function verify(Request $request): WpSite
    {
        $site = WpSite::where('site_key', (string) $request->header('X-CC-Site'))->first();
        $ts = (string) $request->header('X-CC-Timestamp');
        $sig = (string) $request->header('X-CC-Signature');

        if (! $site || $ts === '' || $sig === '' || abs(time() - (int) $ts) > 300
            || ! hash_equals(self::sign($site->secret, $ts, $request->getContent()), $sig)) {
            throw new RuntimeException('Nieprawidłowy podpis lub nieznana strona.');
        }

        return $site;
    }

    /** Dane od wtyczki: pełny raport albo pojedyncze zdarzenie. */
    public function ingest(WpSite $site, array $payload): void
    {
        $site->forceFill(['last_seen_at' => now(), 'offline_alerted_at' => null])->save();

        match ($payload['kind'] ?? null) {
            'report' => $this->storeReport($site, (array) Arr::get($payload, 'data.report', []), Arr::get($payload, 'data.scan')),
            'event' => $this->storeEvent($site, (array) ($payload['data'] ?? [])),
            'events' => $this->storeEvents($site, array_slice((array) Arr::get($payload, 'data.events', []), 0, 300)),
            default => throw new RuntimeException('Nieznany typ danych.'),
        };
    }

    /** Panel odpytuje stronę (raport + opcjonalnie skan plików). */
    public function pull(WpSite $site, bool $scan = true): void
    {
        $url = $site->rest_url ?: rtrim($site->client->website_url, '/').'/wp-json/cc-connector/v1/report';
        $body = json_encode(['scan' => $scan, 'nonce' => Str::random(16)]);
        $ts = (string) time();

        try {
            $res = Http::timeout(180)->withUserAgent('CC-Panel/1.0')->withHeaders([
                'X-CC-Site' => $site->site_key,
                'X-CC-Timestamp' => $ts,
                'X-CC-Signature' => self::sign($site->secret, $ts, $body),
            ])->withBody($body, 'application/json')->post($url);
        } catch (Throwable $e) {
            $site->update(['last_error' => 'Brak połączenia ze stroną: '.Str::limit($e->getMessage(), 200), 'last_pull_at' => now()]);
            throw new RuntimeException($site->last_error);
        }

        if ($res->failed() || ! is_array($res->json('report'))) {
            $msg = 'Strona odpowiedziała kodem '.$res->status().($res->json('message') ? ': '.$res->json('message') : '');
            $site->update(['last_error' => $msg, 'last_pull_at' => now()]);
            throw new RuntimeException($msg);
        }

        $site->forceFill(['last_seen_at' => now(), 'last_pull_at' => now(), 'offline_alerted_at' => null])->save();
        $this->storeReport($site, $res->json('report'), $res->json('scan'));
    }

    // ------------------------------------------------------------------ raport i skan

    private function storeReport(WpSite $site, array $report, ?array $scan): void
    {
        $previous = $site->report;
        $site->forceFill([
            'status' => 'connected',
            'report' => $this->sanitizeReport($report),
            'rest_url' => filter_var($report['rest_url'] ?? null, FILTER_VALIDATE_URL) ? $report['rest_url'] : $site->rest_url,
            'last_report_at' => now(),
            'last_error' => null,
        ])->save();

        // Nowi administratorzy wykryci po porównaniu list (np. dopisani bezpośrednio w bazie – wtedy WordPress nie wywołuje zdarzeń)
        if ($previous && isset($previous['admins'], $report['admins'])) {
            $before = collect($previous['admins'])->pluck('login')->all();
            foreach (collect($report['admins'])->reject(fn ($a) => in_array($a['login'] ?? '', $before, true)) as $a) {
                $this->createEvent($site, 'admin_added', 'critical', 'Nowy administrator na liście: '.($a['login'] ?? '?'),
                    ['target' => $a['login'] ?? null, 'target_email' => $a['email'] ?? null, 'how' => 'wykryto w raporcie (porównanie z poprzednim)'], dedupeHours: 24);
            }
        }

        if ($scan) {
            $site->forceFill(['scan' => $scan])->save();
            $this->scanEvents($site, $scan);
        }
    }

    private function scanEvents(WpSite $site, array $scan): void
    {
        $list = fn (string $k) => array_values(array_filter((array) ($scan[$k] ?? []), 'is_string'));
        $count = fn (string $k) => (int) ($scan[$k.'_count'] ?? count($list($k)));

        if ($count('uploads_php')) {
            $this->createEvent($site, 'scan_uploads_php', 'critical', "Pliki PHP w katalogu uploads ({$count('uploads_php')})",
                ['files' => $list('uploads_php'), 'hint' => 'W katalogu z obrazkami nie powinno być plików PHP – typowy ślad włamania (tzw. webshell).'], dedupeHours: 20);
        }
        if ($count('core_modified')) {
            $this->createEvent($site, 'scan_core_modified', 'critical', "Zmienione pliki WordPressa ({$count('core_modified')})",
                ['files' => $list('core_modified'), 'hint' => 'Pliki rdzenia różnią się od oficjalnych z wordpress.org. Rozwiązanie: Kokpit → Aktualizacje → „Zainstaluj ponownie”.'], dedupeHours: 20);
        }
        if ($count('core_unknown')) {
            $this->createEvent($site, 'scan_core_unknown', 'critical', "Nieznane pliki PHP w katalogach WordPressa ({$count('core_unknown')})",
                ['files' => $list('core_unknown'), 'hint' => 'Pliki, których nie ma w oficjalnej paczce WordPressa (wp-admin, wp-includes, katalog główny).'], dedupeHours: 20);
        }
        if (! ($scan['baseline'] ?? false)) {
            $changed = array_merge($list('changed'), $list('added'));
            $sensitive = array_values(array_filter($changed, fn ($f) => str_ends_with($f, 'wp-config.php') || str_ends_with($f, '.htaccess')));
            if ($sensitive) {
                $this->createEvent($site, 'scan_config_changed', 'critical', 'Zmiana w wp-config.php lub .htaccess', ['files' => $sensitive]);
            }
            $code = array_values(array_diff($changed, $sensitive));
            if ($code || $count('removed')) {
                $this->createEvent($site, 'scan_files_changed', 'warning',
                    'Zmiany w plikach wtyczek/motywów: '.collect([
                        $count('changed') ? $count('changed').' zmienionych' : null,
                        $count('added') ? $count('added').' nowych' : null,
                        $count('removed') ? $count('removed').' usuniętych' : null,
                    ])->filter()->implode(', '),
                    ['changed' => $list('changed'), 'added' => $list('added'), 'removed' => $list('removed'),
                        'hint' => 'Zmiany poza aktualizacją z kokpitu. Jeśli nikt nie wgrywał plików przez FTP – sprawdź je.']);
            }
        }
    }

    // ------------------------------------------------------------------ zdarzenia

    private const EVENTS = [
        'admin_added' => ['critical', 'Nowy administrator: :target'],
        'admin_removed' => ['warning', 'Usunięto administratora: :target'],
        'admin_login' => ['info', 'Logowanie administratora: :user'],
        'login_attack' => ['warning', 'Seria nieudanych logowań (ponad :count w godzinę)'],
        'file_edited' => ['critical', 'Edycja kodu z kokpitu: :file'],
        'plugin_installed' => ['warning', 'Zainstalowano wtyczkę: :items'],
        'plugin_updated' => ['info', 'Zaktualizowano wtyczki: :items'],
        'plugin_activated' => ['info', 'Włączono wtyczkę: :plugin'],
        'plugin_deactivated' => ['info', 'Wyłączono wtyczkę: :plugin'],
        'plugin_deleted' => ['info', 'Usunięto wtyczkę: :plugin'],
        'theme_installed' => ['warning', 'Zainstalowano motyw: :items'],
        'theme_updated' => ['info', 'Zaktualizowano motyw: :items'],
        'theme_switched' => ['warning', 'Zmieniono motyw na: :theme'],
        'core_updated' => ['info', 'Zaktualizowano :items'],
        'connector_deactivated' => ['critical', 'Wyłączono wtyczkę CC Connector – monitoring przestanie działać'],
    ];

    private function storeEvents(WpSite $site, array $events): void
    {
        foreach ($events as $e) {
            if (is_array($e)) {
                $this->storeEvent($site, $e);
            }
        }
    }

    private function storeEvent(WpSite $site, array $data): void
    {
        $type = (string) ($data['type'] ?? '');
        if (! isset(self::EVENTS[$type])) {
            return;
        }
        [$severity, $title] = self::EVENTS[$type];
        $vars = collect($data)->map(fn ($v) => is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : (string) $v);
        $title = preg_replace_callback('/:(\w+)/', fn ($m) => Str::limit($vars->get($m[1]) ?: '?', 120), $title);

        $details = Arr::only($data, ['user', 'ip', 'target', 'target_email', 'how', 'file', 'where', 'items', 'plugin', 'theme', 'count', 'agent', 'last_login_tried']);
        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $this->createEvent($site, $type, $severity, $title, $details, $at);
    }

    public function createEvent(WpSite $site, string $type, string $severity, string $title, array $details = [], ?Carbon $at = null, int $dedupeHours = 0): ?WpEvent
    {
        if ($dedupeHours && $site->events()->where('type', $type)->where('title', $title)->where('created_at', '>=', now()->subHours($dedupeHours))->exists()) {
            return null;
        }
        $event = $site->events()->create([
            'client_id' => $site->client_id,
            'type' => $type,
            'severity' => $severity,
            'title' => Str::limit($title, 250),
            'details' => $this->truncate($details),
            'occurred_at' => $at ?? now(),
        ]);

        if ($severity === 'critical') {
            $this->alert($event);
        }

        return $event;
    }

    /** Natychmiastowy e-mail do administratorów panelu. Błąd poczty nie może zatrzymać odbioru danych. */
    private function alert(WpEvent $event): void
    {
        try {
            foreach (User::where('is_admin', true)->pluck('email') as $email) {
                Mail::to($email)->send(new WpAlertMail($event));
            }
        } catch (Throwable $e) {
            Log::warning('WP alert mail failed: '.$e->getMessage());
        }
    }

    /** Strony bez kontaktu > 36 h (wtyczka usunięta, strona nie działa, blokada). */
    public function checkOffline(): int
    {
        $n = 0;
        WpSite::where('status', 'connected')->where('last_seen_at', '<', now()->subHours(36))->whereNull('offline_alerted_at')
            ->whereHas('client', fn ($q) => $q->active())->each(function (WpSite $site) use (&$n) {
                $this->createEvent($site, 'site_offline', 'warning', 'Brak kontaktu ze stroną od '.$site->last_seen_at->diffForHumans(),
                    ['hint' => 'Sprawdź, czy strona działa i czy wtyczka CC Connector jest na miejscu (wp-content/mu-plugins).']);
                $site->forceFill(['offline_alerted_at' => now()])->save();
                $n++;
            });

        return $n;
    }

    // ------------------------------------------------------------------ pomocnicze

    public static function phpStatus(?string $version): array
    {
        if (! $version || ! preg_match('/^(\d+\.\d+)/', $version, $m)) {
            return ['status' => 'unknown', 'eol' => null];
        }
        $eol = self::PHP_EOL[$m[1]] ?? null;
        if (! $eol) {
            return ['status' => version_compare($m[1], '8.5', '>') ? 'ok' : 'eol', 'eol' => null];
        }
        $date = Carbon::parse($eol);

        return ['status' => $date->isPast() ? 'eol' : ($date->lt(now()->addMonths(6)) ? 'soon' : 'ok'), 'eol' => $eol];
    }

    private function sanitizeReport(array $r): array
    {
        $r['plugins'] = array_slice(array_values((array) ($r['plugins'] ?? [])), 0, 500);
        $r['themes'] = array_slice(array_values((array) ($r['themes'] ?? [])), 0, 100);
        $r['admins'] = array_slice(array_values((array) ($r['admins'] ?? [])), 0, 200);

        return $r;
    }

    private function truncate(array $details): array
    {
        return collect($details)->map(fn ($v) => is_array($v) ? array_slice($v, 0, 100) : (is_string($v) ? Str::limit($v, 500) : $v))->all();
    }
}
