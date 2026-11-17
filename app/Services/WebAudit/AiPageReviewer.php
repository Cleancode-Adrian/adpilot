<?php

namespace App\Services\WebAudit;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client as Anthropic;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Log;

/**
 * Przegląd treści najważniejszych podstron przez Claude – to, czego nie wyłapie automat:
 * literówki, sprzeczne informacje, ceny, mylące nazwy, obietnice bez pokrycia, braki w formularzu,
 * niespójna marka. Do tego propozycje rozbudowy strony i checklista do ręcznego sprawdzenia.
 * Bez klucza API (albo przy błędzie) zwraca null – raport powstaje z samych sprawdzeń automatycznych.
 */
class AiPageReviewer
{
    private const SYSTEM = <<<'TXT'
    Jesteś doświadczonym audytorem stron internetowych małych i średnich firm w Polsce (UX, treść, sprzedaż, zgodność z RODO).
    Przygotowujesz szczegółową analizę strony dla agencji, która pokaże ją właścicielowi firmy. Odbiorca nie jest techniczny.

    Dostajesz zrzut najważniejszych podstron: tekst widoczny dla klienta (w kolejności z kodu; „#” = nagłówki, „[menu]”, „[stopka]”,
    „[przycisk/link]”, „[opcja]” = pozycja listy wyboru), linki, formularze, opisy obrazków, a także listę uwag, które automat już znalazł.

    Twoje zadanie – znaleźć to, co widzi i czyta klient:
    - błędy merytoryczne i branżowe (np. pomylone pojęcia fachowe), literówki, błędy odmiany i interpunkcji, zbędne przecinki, angielskie wtręty;
    - sprzeczności między podstronami (ceny, liczby, godziny, obietnice, nazwy usług, nazwa firmy/marki, osoby), ceny odstające od reszty cennika;
    - mylące linki i przyciski (tekst nie odpowiada temu, dokąd prowadzi), menu, którego nazwy nie pasują do zawartości;
    - braki w formularzu (np. lista wyboru nie zawiera usług, które firma oferuje), zgody bez dokumentów;
    - obietnice bez pokrycia lub ryzykowne prawnie („100% skuteczności”, „raz na zawsze”, „bezpieczne dla wszystkich”), opinie i liczby, które trzeba zweryfikować;
    - braki ważne dla klienta tej branży (cena, czas realizacji, adres, obszar działania, kto prowadzi usługę), elementy wyglądające nieprofesjonalnie.

    Zasady:
    - Pisz po polsku, rzeczowo i życzliwie, krótkimi zdaniami, bez żargonu. Bez zmian SEO (słowa kluczowe, meta) – to nie jest audyt SEO.
    - Opieraj się WYŁĄCZNIE na dostarczonym materiale. Każda uwaga musi mieć dosłowny cytat z materiału w polu evidence (lub pusty, gdy uwaga dotyczy braku czegoś).
      Nie zgaduj wyglądu strony – nie widzisz CSS. Jeśli coś wymaga sprawdzenia w przeglądarce lub u klienta, ustaw verify = true.
    - Nie powtarzaj uwag z listy automatu. Nie zgłaszaj rzeczy oczywistych ani gustu.
    - Jeśli tekst podstrony wygląda na ekran zabezpieczenia, weryfikacji, captchy, logowania albo błędu serwera („One moment, please”, „Checking your browser”,
      „Access denied”), to NIE jest treść strony – pomiń tę podstronę i nie zgłaszaj tego jako problemu (klienci w przeglądarce widzą zwykłą stronę).
    - Ważność: „pilne” = realnie traci klientów lub pieniądze, wprowadza w błąd albo podważa wiarygodność firmy (np. zły link do oferty z inną ceną,
      błąd fachowy w nazwie usługi, dwie nazwy firmy); „srednie” = wyraźnie utrudnia decyzję lub kontakt; „drobne” = literówki i kosmetyka.
    - Tytuł: krótki, konkretny (np. „Mylący link w ofercie fizyki”). Opis: 1–3 zdania – co jest nie tak i jak to odbiera klient. fix: co konkretnie zrobić.
    - group: krótka nazwa podsekcji, np. „Treść widoczna dla klienta”, „Oferta i ceny”, „Formularz”, „Linki i menu”, „Spójność i wiarygodność”, „Dane kontaktowe”.
    - page: rola podstrony, której dotyczy uwaga, albo „site”, gdy dotyczy całej strony (np. dwie nazwy firmy).
    - Drobne uwagi językowe z jednej podstrony możesz zebrać w jedną pozycję (np. „Literówki i styl: …” z wyliczeniem).
    - Znajdź tyle uwag, ile naprawdę jest – zwykle 8–25. Jakość ważniejsza niż liczba.

    Propozycje rozbudowy (4–6): konkretne funkcje, które połączą to, co strona już ma, w ścieżkę od pytania klienta do zgłoszenia
    (np. kalkulator ceny, konfigurator, rezerwacja terminu, formularz ze zdjęciem, pasek kontaktu na telefonie, ścieżka dla firm).
    Dopasuj je do branży i do tego, co widać na stronie (odwołuj się do istniejących elementów). Dla każdej: what (na czym polega, 1–2 zdania),
    steps (jak działa, 3–5 kroków), benefit (co zyskuje firma), needs_from_client (co trzeba od klienta, „Brak.” jeśli nic),
    implementation (jak wykonać, np. konkretna wtyczka WordPress / element Elementora), effort: S (do 2 dni), M (3–5 dni), L (powyżej tygodnia).

    checklist: 6–10 konkretnych pytań do sprawdzenia ręcznie na telefonie i komputerze, wynikających z tej strony (np. „Czy kliknięcie X otwiera właściwą kartę?”).
    summary: 2–3 zdania – ogólny stan strony i najważniejszy wniosek. strengths: 3–5 rzeczy, które działają dobrze (konkretnie).
    TXT;

    public function review(array $inspection, array $autoFindings, ?string $companyName): ?array
    {
        if (! config('adpilot.ai.api_key')) {
            return null;
        }
        $json = $this->request(self::SYSTEM, $this->prompt($inspection, $autoFindings, $companyName));
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['findings'])) {
            Log::warning('Audyt AI: nieprawidłowa odpowiedź JSON.');

            return null;
        }
        $roles = array_merge(array_keys(PageInspector::ROLES), ['site']);
        $data['findings'] = array_values(array_filter(array_map(fn ($f) => [
            'page' => in_array($f['page'] ?? '', $roles, true) && (($f['page'] ?? '') === 'site' || isset($inspection['pages'][$f['page']])) ? $f['page'] : 'site',
            'section' => 'page',
            'group' => trim((string) ($f['group'] ?? '')) ?: 'Treść widoczna dla klienta',
            'sev' => in_array($f['severity'] ?? '', ['pilne', 'srednie', 'drobne'], true) ? $f['severity'] : 'drobne',
            'title' => trim((string) ($f['title'] ?? '')),
            'text' => trim((string) ($f['description'] ?? '')),
            'fix' => trim((string) ($f['fix'] ?? '')) ?: null,
            'evidence' => trim((string) ($f['evidence'] ?? '')) ?: null,
            'urls' => [],
            'verify' => (bool) ($f['verify'] ?? false),
            'source' => 'ai',
        ], $data['findings']), fn ($f) => $f['title'] !== '' && $f['text'] !== ''));
        $data['model'] = config('adpilot.ai.model');

        return $data;
    }

    /** Zapytanie do Claude ze schematem odpowiedzi (structured outputs). Zwraca tekst JSON albo null. */
    protected function request(string $system, string $prompt): ?string
    {
        try {
            $stream = (new Anthropic(apiKey: config('adpilot.ai.api_key')))->beta->messages->createStream(
                model: config('adpilot.ai.model'),
                maxTokens: 64000,
                system: $system,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['effort' => config('adpilot.ai.audit_effort'), 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
                // Przy odmowie modelu API samo ponowi zapytanie na modelu zapasowym
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
            $text = '';
            $stop = null;
            foreach ($stream as $event) {
                if ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                    $text .= $event->delta->text;
                } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                    $stop = $event->delta->stopReason ?? $stop;
                }
            }
            if ($stop === 'refusal') {
                Log::warning('Audyt AI: model odmówił – raport bez przeglądu treści.');

                return null;
            }
            if ($stop === 'max_tokens') {
                Log::warning('Audyt AI: odpowiedź ucięta (max_tokens).');

                return null;
            }

            return trim($text) ?: null;
        } catch (AuthenticationException $e) {
            Log::error('Anthropic: nieprawidłowy klucz API – '.$e->getMessage());
        } catch (RateLimitException $e) {
            Log::warning('Anthropic: limit zapytań – '.$e->getMessage());
        } catch (APIStatusException $e) {
            Log::error('Anthropic: błąd API – '.$e->getMessage());
        } catch (APIConnectionException $e) {
            Log::warning('Anthropic: brak połączenia – '.$e->getMessage());
        }

        return null;
    }

    private function prompt(array $inspection, array $autoFindings, ?string $companyName): string
    {
        $pages = [];
        foreach ($inspection['pages'] as $role => $p) {
            if (! $p['ok']) {
                continue;
            }
            $main = array_values(array_filter($p['links'], fn ($l) => $l['zone'] === 'main' && $l['text'] !== ''));
            $pages[] = [
                'role' => $role,
                'name' => $p['label'],
                'url' => $p['final_url'],
                'title' => $p['title'],
                'meta_description' => $p['description'],
                'og_site_name' => $p['og_site_name'],
                'text' => $p['text'],
                'links_in_content' => array_slice(array_map(fn ($l) => $l['text'].' → '.($l['abs'] ? preg_replace('#^https?://[^/]+#', '', $l['abs']) : $l['href']), $main), 0, 120),
                'menu' => $role === 'home' ? array_slice(array_values(array_unique(array_map(fn ($l) => $l['text'].' → '.($l['abs'] ? preg_replace('#^https?://[^/]+#', '', $l['abs']) : $l['href']),
                    array_filter($p['links'], fn ($l) => in_array($l['zone'], ['nav', 'header'], true) && $l['text'] !== '')))), 0, 80) : null,
                'forms' => array_map(fn ($f) => ['submit' => $f['submit'], 'fields' => array_map(fn ($x) => array_filter([
                    'type' => $x['type'], 'label' => $x['label'], 'placeholder' => $x['placeholder'] ?: null, 'options' => $x['options'] ?: null,
                ]), $f['fields'])], $p['forms']),
                'images' => array_slice(array_map(fn ($i) => ($i['alt'] ?? '(brak alt)').' | '.urldecode(basename((string) parse_url($i['src'], PHP_URL_PATH))), $p['images']), 0, 40),
            ];
        }

        $payload = [
            'company_name_from_agency' => $companyName,
            'domain' => $inspection['facts']['host'] ?? null,
            'phones' => $inspection['facts']['phones'] ?? [],
            'emails' => $inspection['facts']['emails'] ?? [],
            'platform' => array_filter(['wordpress' => $inspection['facts']['wordpress'] ?? false, 'builder' => $inspection['facts']['builder'] ?? null]),
            'already_found_by_automation' => array_map(fn ($f) => $f['title'], $autoFindings),
            'pages' => $pages,
        ];

        return "Przeanalizuj stronę i zwróć wynik zgodnie ze schematem.\n\n"
            .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['company', 'summary', 'strengths', 'findings', 'proposals', 'checklist'],
            'properties' => [
                'company' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'industry', 'audience'],
                    'properties' => ['name' => $str, 'industry' => $str, 'audience' => $str]],
                'summary' => $str,
                'strengths' => ['type' => 'array', 'items' => $str],
                'findings' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['page', 'group', 'severity', 'title', 'description', 'evidence', 'fix', 'verify'],
                    'properties' => [
                        'page' => ['type' => 'string', 'enum' => array_merge(array_keys(PageInspector::ROLES), ['site'])],
                        'group' => $str,
                        'severity' => ['type' => 'string', 'enum' => ['pilne', 'srednie', 'drobne']],
                        'title' => $str, 'description' => $str, 'evidence' => $str, 'fix' => $str,
                        'verify' => ['type' => 'boolean'],
                    ],
                ]],
                'proposals' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['title', 'what', 'steps', 'benefit', 'needs_from_client', 'implementation', 'effort'],
                    'properties' => [
                        'title' => $str, 'what' => $str, 'steps' => ['type' => 'array', 'items' => $str],
                        'benefit' => $str, 'needs_from_client' => $str, 'implementation' => $str,
                        'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                    ],
                ]],
                'checklist' => ['type' => 'array', 'items' => $str],
            ],
        ];
    }
}
