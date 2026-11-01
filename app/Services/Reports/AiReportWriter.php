<?php

namespace App\Services\Reports;

use Anthropic\Client as Anthropic;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Komentarz do raportu pisany przez Claude. Bez klucza API (lub przy błędzie)
 * zwraca podsumowanie z szablonu – raport powstaje zawsze.
 */
class AiReportWriter
{
    private const SYSTEM_CLIENT = <<<'TXT'
    Jesteś doświadczonym specjalistą Google Ads i Meta Ads w agencji performance marketingu.
    Piszesz miesięczny komentarz do raportu dla właściciela sklepu internetowego – osoby, która nie zna żargonu reklamowego.

    Zasady:
    - Pisz po polsku, rzeczowo i życzliwie, w formie "my" (agencja). Bez lania wody i bez obietnic bez pokrycia.
    - Opieraj się wyłącznie na liczbach z danych. Nie wymyślaj wartości. Jeśli brak danych (np. GA4), napisz to wprost.
    - Dopasuj język do celu klienta (pole client.goal): "sales" = sprzedaż w sklepie (przychód, ROAS, zamówienia);
      "leads" = pozyskiwanie kontaktów – mów o zapytaniach/leadach, koszcie pozyskania leada, wysłanych formularzach,
      rejestracjach i telefonach (kpis.analytics.leads, kpis.events), a NIE o przychodzie i ROAS; "mixed" = oba.
    - Tłumacz skróty przy pierwszym użyciu (np. ROAS = przychód z reklam / koszt reklam, CPL = koszt pozyskania leada).
    - Wyjaśnij różnice między danymi platform a GA4 jednym zdaniem, jeśli są istotne (różne modele atrybucji).
    - Format: Markdown z sekcjami "## Podsumowanie", "## Co zadziałało", "## Co poprawiamy", "## Plan na kolejny okres".
      Krótkie akapity i listy. Maksymalnie ok. 350 słów.
    TXT;

    private const SYSTEM_INTERNAL = <<<'TXT'
    Jesteś senior performance marketerem (Google Ads, Meta Ads, GA4) i przygotowujesz plan pracy dla specjalisty prowadzącego konto.

    Zasady:
    - Po polsku, konkretnie, językiem specjalisty. Opieraj się wyłącznie na danych; nie wymyślaj liczb.
    - Ustal priorytety wg wpływu na wynik (pieniądze) i pilności. Grupuj powiązane problemy.
    - Dla każdego zadania: co dokładnie zrobić w panelu, dlaczego (liczby) i jak zmierzyć efekt.
    - Zaznacz ryzyka (np. reset fazy nauki przy dużych zmianach budżetu) i rzeczy do weryfikacji ręcznej.
    - Format: Markdown – "## Diagnoza" (3–5 punktów), "## Plan działań" (lista numerowana, najważniejsze najpierw),
      "## Do obserwacji". Maksymalnie ok. 450 słów.
    TXT;

    public function write(Client $client, string $type, array $data): array
    {
        $key = config('adpilot.ai.api_key');
        if (! $key) {
            return [$this->fallback($type, $data), 'szablon'];
        }

        $payload = $data;
        unset($payload['daily'], $payload['platform_names']);
        $payload['weekly'] = $this->weekly($data['daily']);

        $model = config('adpilot.ai.model');
        $prompt = ($type === 'client'
            ? "Napisz komentarz do raportu dla klienta za okres {$data['kpis']['period']['from']} – {$data['kpis']['period']['to']}."
            : 'Przygotuj plan działań dla specjalisty na najbliższe 2 tygodnie.')
            ."\n\nDane (JSON, kwoty w {$client->currency}; 'previous' = poprzedni okres tej samej długości; 'done' = zadania wykonane, 'open' = otwarte rekomendacje systemu):\n"
            .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $anthropic = new Anthropic(apiKey: $key);
            $message = $anthropic->beta->messages->create(
                model: $model,
                maxTokens: 16000,
                system: $type === 'client' ? self::SYSTEM_CLIENT : self::SYSTEM_INTERNAL,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['effort' => config('adpilot.ai.effort')],
                // Przy odmowie modelu API samo ponowi zapytanie na modelu zapasowym
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            if ($message->stopReason === 'refusal') {
                Log::warning('AI odmówiło napisania raportu – używam szablonu.');

                return [$this->fallback($type, $data), 'szablon'];
            }

            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }

            return [trim($text) ?: $this->fallback($type, $data), $message->model];
        } catch (AuthenticationException $e) {
            Log::error('Anthropic: nieprawidłowy klucz API – '.$e->getMessage());
        } catch (RateLimitException $e) {
            Log::warning('Anthropic: limit zapytań – '.$e->getMessage());
        } catch (APIStatusException $e) {
            Log::error('Anthropic: błąd API – '.$e->getMessage());
        } catch (APIConnectionException $e) {
            Log::warning('Anthropic: brak połączenia – '.$e->getMessage());
        }

        return [$this->fallback($type, $data), 'szablon'];
    }

    /** Test połączenia z API (strona Status). Zwraca [tekst|null, model]. */
    public function ping(): array
    {
        try {
            $message = (new Anthropic(apiKey: config('adpilot.ai.api_key')))->messages->create(
                model: config('adpilot.ai.model'),
                maxTokens: 1000,
                messages: [['role' => 'user', 'content' => 'Odpowiedz jednym krótkim zdaniem po polsku: czy połączenie działa?']],
                outputConfig: ['effort' => 'low'],
            );
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    return [trim($block->text), $message->model];
                }
            }
        } catch (AuthenticationException $e) {
            Log::error('Anthropic: nieprawidłowy klucz API – '.$e->getMessage());
        } catch (RateLimitException $e) {
            Log::warning('Anthropic: limit zapytań – '.$e->getMessage());
        } catch (APIStatusException $e) {
            Log::error('Anthropic: błąd API – '.$e->getMessage());
        } catch (APIConnectionException $e) {
            Log::warning('Anthropic: brak połączenia – '.$e->getMessage());
        }

        return [null, config('adpilot.ai.model')];
    }

    private function weekly(array $daily): array
    {
        return collect($daily)->chunk(7)->map(fn ($w) => [
            'from' => $w->first()['date'], 'to' => $w->last()['date'],
            'cost' => round($w->sum('google_cost') + $w->sum('meta_cost'), 2),
            'ads_value' => round($w->sum('ads_value'), 2),
            'sessions' => $w->sum('sessions'),
            'revenue_ga4' => round($w->sum('revenue'), 2),
        ])->values()->all();
    }

    /** Podsumowanie bez AI – generowane z szablonu. */
    public function fallback(string $type, array $data): string
    {
        $k = $data['kpis'];
        $cur = $data['client']['currency'];
        $money = fn ($v) => number_format((float) $v, 0, ',', ' ').' '.$cur;
        $delta = fn ($d) => $d === null ? '' : ' ('.($d >= 0 ? '+' : '').$d.'% vs poprzedni okres)';
        $ads = $k['ads'];

        if ($type === 'internal') {
            $lines = ["## Diagnoza", '',
                "- Koszt: {$money($ads['cost']['value'])}{$delta($ads['cost']['delta'])}, ROAS: ".($ads['roas']['value'] ?? '–').$delta($ads['roas']['delta']),
                '- Otwarte rekomendacje: '.count($data['open']).' (krytyczne: '.collect($data['open'])->where('severity', 'critical')->count().')',
                '', '## Plan działań', ''];
            foreach (array_slice($data['open'], 0, 10) as $i => $r) {
                $lines[] = ($i + 1).". **{$r['title']}** – {$r['action']}";
            }
            $lines[] = '';
            $lines[] = '_Plan wygenerowany z szablonu. Dodaj ANTHROPIC_API_KEY w .env, aby otrzymywać analizę AI._';

            return implode("\n", $lines);
        }

        $an = $k['analytics'];
        if (($data['client']['goal'] ?? 'sales') === 'leads') {
            $lines = ['## Podsumowanie', '',
                "W okresie {$k['period']['from']} – {$k['period']['to']} zainwestowaliśmy w reklamy {$money($ads['cost']['value'])}{$delta($ads['cost']['delta'])}. "
                ."Reklamy przyniosły {$ads['conversions']['value']} zapytań (leadów){$delta($ads['conversions']['delta'])}, "
                .'a średni koszt pozyskania jednego kontaktu wyniósł '.($ads['cpa']['value'] ? $money($ads['cpa']['value']) : '–').'.'];
            if ($k['has_analytics']) {
                $lines[] = '';
                $lines[] = "Stronę odwiedzono {$an['sessions']['value']} razy{$delta($an['sessions']['delta'])}. Google Analytics zarejestrował {$an['leads']['value']} wysłanych formularzy, rejestracji i kliknięć w telefon (wszystkie źródła ruchu).";
            }
            $lines[] = '';
            $lines[] = '## Plan na kolejny okres';
            $lines[] = '';
            foreach (array_slice($data['open'], 0, 5) as $r) {
                $lines[] = "- {$r['title']}";
            }

            return implode("
", $lines);
        }
        $best = collect($data['campaigns'])->filter(fn ($c) => $c['roas'])->sortByDesc('roas')->first();
        $lines = ['## Podsumowanie', '',
            "W okresie {$k['period']['from']} – {$k['period']['to']} zainwestowaliśmy w reklamy {$money($ads['cost']['value'])}{$delta($ads['cost']['delta'])}. "
            ."Reklamy przyniosły {$money($ads['conversion_value']['value'])} przychodu{$delta($ads['conversion_value']['delta'])}, "
            .'co daje ROAS '.round(($ads['roas']['value'] ?? 0) * 100).'% (każda złotówka w reklamie zwróciła się '.number_format($ads['roas']['value'] ?? 0, 2, ',', '').' zł).',
        ];
        if ($k['has_analytics']) {
            $lines[] = '';
            $lines[] = "Sklep odwiedziło {$an['sessions']['value']} sesji{$delta($an['sessions']['delta'])}, zrealizowano {$an['transactions']['value']} transakcji o łącznej wartości {$money($an['revenue']['value'])} (dane Google Analytics, wszystkie źródła ruchu).";
        }
        $lines[] = '';
        $lines[] = '## Co zadziałało';
        $lines[] = '';
        if ($best) {
            $lines[] = "- Najskuteczniejsza kampania: **{$best['name']}** – ROAS ".round($best['roas'] * 100).'%.';
        }
        foreach (array_slice($data['done'], 0, 5) as $r) {
            $lines[] = "- Wykonane: {$r['title']}";
        }
        $lines[] = '';
        $lines[] = '## Plan na kolejny okres';
        $lines[] = '';
        foreach (array_slice($data['open'], 0, 5) as $r) {
            $lines[] = "- {$r['title']}";
        }

        return implode("\n", $lines);
    }
}
