<?php

namespace App\Services\Care;

use App\Models\CareTask;
use App\Models\CareTemplate;
use App\Models\Client;
use Carbon\CarbonImmutable;

/**
 * Plan opieki nad stroną: z szablonów (zadań cyklicznych) tworzy zadania na dany miesiąc.
 * Operacja jest idempotentna – można ją wołać przy każdym wejściu na stronę i z harmonogramu.
 */
class CarePlanner
{
    /** Gotowe pakiety zadań do dodania jednym kliknięciem. [tytuł, opis, częstotliwość, dzień terminu] */
    public const PRESETS = [
        'security' => [
            'label' => 'Bezpieczeństwo strony',
            'items' => [
                ['Kopia zapasowa – sprawdź, czy się wykonuje', 'Zobacz datę ostatniej kopii plików i bazy (hosting / wtyczka). Kopia musi być też poza serwerem.', 'monthly', 5],
                ['Skan złośliwego oprogramowania', 'Wordfence / Sucuri / skaner hostingu. Sprawdź zmienione pliki rdzenia i podejrzane pliki PHP w uploads.', 'monthly', 10],
                ['Przegląd kont administratorów', 'Usuń zbędne konta i nieznanych adminów. Sprawdź, czy wszyscy admini mają włączone 2FA.', 'monthly', 10],
                ['Przegląd logowań i blokad', 'Nieudane logowania, zablokowane IP, alerty z wtyczki bezpieczeństwa lub firewalla.', 'monthly', 10],
                ['Certyfikat SSL i domena – ważność', 'Data wygaśnięcia SSL i domeny, przekierowanie http → https, brak ostrzeżeń „mixed content”.', 'monthly', 15],
                ['Dostępność strony i błędy', 'Monitoring uptime, błędy 404/500, logi błędów PHP, Google Search Console → Bezpieczeństwo.', 'monthly', 15],
                ['Test przywrócenia kopii', 'Przywróć kopię na środowisku testowym – kopia, której nie da się przywrócić, nie jest kopią.', 'quarterly', 20],
                ['Zmiana haseł do kluczowych dostępów', 'Panel strony, hosting, FTP/SSH, baza danych. Po zmianie zaktualizuj je w zakładce Dostępy.', 'semiannual', 20],
            ],
        ],
        'wordpress' => [
            'label' => 'Aktualizacje WordPress',
            'items' => [
                ['Kopia zapasowa przed aktualizacją', 'Pełna kopia plików i bazy bezpośrednio przed aktualizacjami.', 'monthly', 10],
                ['Aktualizacja WordPressa (rdzeń)', 'Najpierw przeczytaj informacje o wydaniu przy wersjach głównych.', 'monthly', 10],
                ['Aktualizacja wtyczek', 'Wtyczki płatne: sprawdź ważność licencji. Zanotuj wtyczki, których nie da się zaktualizować.', 'monthly', 10],
                ['Aktualizacja motywu', 'Motyw potomny zostaje bez zmian – sprawdź, czy nadpisane szablony nie są przestarzałe (WooCommerce → Status).', 'monthly', 10],
                ['Test strony po aktualizacji', 'Strona główna, menu, formularze kontaktowe (wyślij testowe zgłoszenie), koszyk i płatność jeśli to sklep, wersja mobilna.', 'monthly', 11],
                ['Porządek we wtyczkach', 'Usuń nieaktywne i porzucone wtyczki (bez aktualizacji > 1 rok). Mniej wtyczek = mniej luk.', 'quarterly', 15],
                ['Wersja PHP na hostingu', 'Sprawdź, czy wersja PHP jest wspierana; zaplanuj podniesienie, jeśli zbliża się koniec wsparcia.', 'semiannual', 15],
            ],
        ],
        'ads_tracking' => [
            'label' => 'Reklamy – kontrola konta i pomiaru',
            'items' => [
                ['Test zdarzenia Lead / zakupu', 'Wyślij testowy formularz (albo zamówienie) i sprawdź: Meta Pixel Helper / Menedżer zdarzeń → Testuj zdarzenia oraz GA4 → DebugView. Zdarzenie musi dojść do obu.', 'monthly', 5],
                ['Linki reklam – parametry UTM', 'Każda aktywna reklama ma utm_source / utm_medium / utm_campaign (np. facebook / paid). Bez tego GA4 pokaże ruch jako „direct”.', 'monthly', 5],
                ['Meta: zestaw danych połączony z domeną', 'Menedżer zdarzeń → zestaw danych (piksel) → Ustawienia → połączone konta reklamowe i domena. Ostrzeżenia przy zestawach reklam panel pokazuje sam.', 'monthly', 10],
                ['Meta: weryfikacja reklamodawcy i płatności', 'Ustawienia firmy → Centrum bezpieczeństwa: weryfikacja reklamodawcy, 2FA administratorów, metoda płatności konta reklamowego.', 'quarterly', 10],
                ['Baner cookies (CMP) – plan i odsetek zgód', 'Sprawdź, czy kończy się okres próbny / abonament (Cookiebot, CookieYes…) i odsetek zgód. Spadek poniżej ~70% = mniej danych w GA4 i Meta.', 'monthly', 15],
                ['Umiejscowienia Meta', 'Wyniki reklam → Umiejscowienia: czy nie wróciły kliknięcia z Audience Network bez wejść na stronę (Meta potrafi je włączyć w nowych kampaniach Advantage+).', 'monthly', 15],
                ['Conversions API (zdarzenia z serwera)', 'Czy zdarzenia z serwera dochodzą do Meta i deduplikują się z pikselem (ten sam event_id). Menedżer zdarzeń → Przegląd → „Wiele źródeł”.', 'quarterly', 20],
            ],
        ],
        'basic' => [
            'label' => 'Podstawowa opieka',
            'items' => [
                ['Kopia zapasowa – sprawdź', 'Czy kopia się wykonuje i jest przechowywana poza serwerem.', 'monthly', 5],
                ['Aktualizacje', 'CMS, wtyczki, motyw – po kopii zapasowej.', 'monthly', 10],
                ['Sprawdzenie działania strony', 'Formularze, kluczowe podstrony, szybkość, wersja mobilna.', 'monthly', 12],
                ['Raport dla klienta', 'Krótkie podsumowanie prac z miesiąca.', 'monthly', 28],
            ],
        ],
    ];

    public static function period(?CarbonImmutable $month = null): string
    {
        return ($month ?? CarbonImmutable::now())->format('Y-m');
    }

    /** Tworzy brakujące zadania z planu na dany miesiąc. Zwraca liczbę nowych zadań. */
    public function ensureMonth(Client $client, ?CarbonImmutable $month = null): int
    {
        $month = ($month ?? CarbonImmutable::now())->startOfMonth();
        $period = self::period($month);
        $created = 0;

        foreach ($client->careTemplates()->where('active', true)->get() as $t) {
            if (! $t->isDueIn($month->month)) {
                continue;
            }
            // Nie odtwarzaj zadań w miesiącach sprzed powstania szablonu
            if ($t->created_at && $month->lt(CarbonImmutable::parse($t->created_at)->startOfMonth())) {
                continue;
            }
            $task = CareTask::firstOrCreate(
                ['care_template_id' => $t->id, 'period' => $period],
                [
                    'client_id' => $client->id,
                    'title' => $t->title,
                    'description' => $t->description,
                    'due_date' => $month->day(min($t->due_day, $month->daysInMonth))->toDateString(),
                ],
            );
            $created += (int) $task->wasRecentlyCreated;
        }

        return $created;
    }

    /** Dla wszystkich aktywnych klientów – wołane codziennie z harmonogramu. */
    public function ensureAll(?CarbonImmutable $month = null): int
    {
        return Client::active()->whereHas('careTemplates')->get()->sum(fn (Client $c) => $this->ensureMonth($c, $month));
    }

    /** Dodaje pakiet (pomija zadania o tym samym tytule) i od razu planuje bieżący miesiąc. */
    public function applyPreset(Client $client, string $key): int
    {
        $preset = self::PRESETS[$key] ?? throw new \InvalidArgumentException("Nieznany pakiet: $key");
        $existing = $client->careTemplates()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
        $position = (int) $client->careTemplates()->max('position');
        $added = 0;

        foreach ($preset['items'] as [$title, $description, $frequency, $dueDay]) {
            if (in_array(mb_strtolower($title), $existing, true)) {
                continue;
            }
            $client->careTemplates()->create([
                'title' => $title, 'description' => $description, 'frequency' => $frequency,
                'start_month' => now()->month, 'due_day' => $dueDay, 'position' => ++$position,
            ]);
            $added++;
        }
        $client->has_care || $client->update(['has_care' => true]);
        $this->ensureMonth($client);

        return $added;
    }

    /** Postęp opieki klienta w danym miesiącu + zaległości z wcześniejszych miesięcy. */
    public function summary(Client $client, ?string $period = null): array
    {
        $period ??= self::period();
        $tasks = $client->careTasks()->where('period', $period)->get(['status', 'due_date']);
        $today = now()->toDateString();

        return [
            'period' => $period,
            'total' => $tasks->count(),
            'done' => $tasks->whereIn('status', ['done', 'skipped'])->count(),
            'overdue' => $client->careTasks()->due(now()->subDay()->toDateString())->count(),
            'due_today' => $client->careTasks()->todo()->where('due_date', $today)->count(),
            // kwoty: wykonane zadania z kwotą w tym miesiącu (razem i jeszcze niezafakturowane)
            'amount' => round((float) $client->careTasks()->where('status', 'done')->whereNotNull('amount')
                ->where(fn ($q) => $q->where('period', $period)->orWhere('done_at', 'like', $period.'%'))->sum('amount'), 2),
            'amount_open' => round((float) $client->careTasks()->where('status', 'done')->whereNotNull('amount')->whereNull('invoiced_at')->sum('amount'), 2),
            // czas pracy: zadania wykonane w tym miesiącu (także zaległe z wcześniejszych)
            'minutes' => (int) $client->careTasks()->where('status', 'done')
                ->where(fn ($q) => $q->where('period', $period)->orWhere('done_at', 'like', $period.'%'))->sum('minutes'),
        ];
    }
}
