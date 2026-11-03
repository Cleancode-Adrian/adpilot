<?php

namespace App\Services\Quotes;

/**
 * Gotowe szablony ofert – pozycje i opisy do edycji. Ceny są przykładowe: popraw je raz,
 * zapisz ofertę jako wzór (duplikuj) i korzystaj dalej.
 * [nazwa, opis, ilość, jednostka, cena netto, cykliczność]
 */
class QuoteTemplates
{
    public const TEMPLATES = [
        'website' => [
            'label' => 'Strona firmowa (WordPress)',
            'title' => 'Strona internetowa firmy',
            'intro' => "Dziękujemy za rozmowę. Poniżej przedstawiamy ofertę wykonania nowej strony internetowej – szybkiej, dopasowanej do telefonów i przygotowanej pod pozyskiwanie klientów z Google i reklam.",
            'items' => [
                ['Projekt graficzny', 'Indywidualny projekt strony głównej i podstron (wersja na komputer i telefon), 2 rundy poprawek.', 1, 'szt.', 2500, null],
                ['Wdrożenie strony na WordPress', 'Do 8 podstron, edycja treści w panelu, formularz kontaktowy, mapa, integracja z social media.', 1, 'szt.', 4000, null],
                ['Podstawowe SEO i analityka', 'Tytuły i opisy, mapa strony, Google Search Console, Google Analytics 4 i Menedżer tagów, baner zgód (RODO).', 1, 'szt.', 900, null],
                ['Szkolenie z obsługi', 'Szkolenie online z edycji treści (do 1 godziny).', 1, 'godz.', 0, null],
                ['Opieka nad stroną', 'Aktualizacje, kopie zapasowe, monitoring dostępności i bezpieczeństwa, drobne zmiany do 1 h/mies.', 1, 'mies.', 250, 'monthly'],
            ],
        ],
        'shop' => [
            'label' => 'Sklep internetowy (WooCommerce)',
            'title' => 'Sklep internetowy',
            'intro' => 'Przedstawiamy ofertę wykonania sklepu internetowego z płatnościami online, wysyłką i pomiarem sprzedaży gotowym pod kampanie reklamowe.',
            'items' => [
                ['Projekt graficzny sklepu', 'Strona główna, kategoria, produkt, koszyk, zamówienie – wersja na komputer i telefon.', 1, 'szt.', 3500, null],
                ['Wdrożenie WooCommerce', 'Konfiguracja sklepu, płatności (Przelewy24/PayU), wysyłki, maile transakcyjne, regulaminy.', 1, 'szt.', 7500, null],
                ['Import produktów', 'Import do 200 produktów z pliku CSV/XML.', 1, 'szt.', 800, null],
                ['Pomiar e-commerce', 'GA4 z danymi sprzedaży, Menedżer tagów, piksel Meta i Conversions API, Google Ads – konwersje.', 1, 'szt.', 1200, null],
                ['Opieka nad sklepem', 'Aktualizacje, kopie, monitoring, bezpieczeństwo, drobne zmiany do 2 h/mies.', 1, 'mies.', 450, 'monthly'],
            ],
        ],
        'care' => [
            'label' => 'Opieka nad stroną',
            'title' => 'Opieka techniczna nad stroną',
            'intro' => 'Zajmiemy się stroną kompleksowo – tak, żeby zawsze działała, była bezpieczna i aktualna. Co miesiąc dostaniesz raport z wykonanych prac.',
            'items' => [
                ['Opieka nad stroną – pakiet Standard', "Aktualizacje WordPressa, wtyczek i motywu, codzienne kopie zapasowe, monitoring dostępności co 5 minut i certyfikatu SSL, skanowanie bezpieczeństwa, comiesięczny raport z prac, drobne zmiany do 1 h.", 1, 'mies.', 300, 'monthly'],
                ['Audyt startowy i porządki', 'Przegląd strony, usunięcie zbędnych wtyczek, zabezpieczenie panelu, konfiguracja kopii.', 1, 'szt.', 500, null],
                ['Dodatkowe prace programistyczne', 'Rozliczane godzinowo, po akceptacji wyceny.', 1, 'godz.', 150, null],
            ],
        ],
        'ads' => [
            'label' => 'Kampanie Google Ads / Meta Ads',
            'title' => 'Prowadzenie kampanii reklamowych',
            'intro' => 'Proponujemy kampanie nastawione na konkretne wyniki – zapytania i sprzedaż, a nie same kliknięcia. Co miesiąc raport z wynikami i planem dalszych działań.',
            'items' => [
                ['Konfiguracja pomiaru', 'Google Analytics 4, Menedżer tagów, konwersje Google Ads, piksel Meta i Conversions API, baner zgód (Consent Mode v2).', 1, 'szt.', 1200, null],
                ['Przygotowanie kampanii', 'Analiza konkurencji, struktura kampanii, teksty i grafiki reklam, grupy odbiorców.', 1, 'szt.', 1500, null],
                ['Prowadzenie kampanii', 'Optymalizacja, testy reklam, wykluczenia, raport miesięczny i spotkanie podsumowujące. Budżet reklamowy płatny osobno bezpośrednio do Google/Meta.', 1, 'mies.', 1200, 'monthly'],
            ],
        ],
        'seo' => [
            'label' => 'Audyt SEO + poprawki',
            'title' => 'Audyt SEO i wdrożenie poprawek',
            'intro' => 'Na podstawie audytu technicznego strony przygotowaliśmy zakres prac, które poprawią widoczność w Google i działanie strony.',
            'items' => [
                ['Audyt SEO i techniczny', 'Pełny audyt: błędy, linki, indeksowanie, szybkość, treści – z listą rekomendacji.', 1, 'szt.', 800, null],
                ['Wdrożenie poprawek technicznych', 'Naprawa niedziałających linków i przekierowań, tytuły i opisy, nagłówki, mapa strony, dane strukturalne.', 1, 'szt.', 1500, null],
                ['Optymalizacja szybkości', 'Cache, kompresja obrazów, optymalizacja wtyczek i ładowania strony.', 1, 'szt.', 900, null],
            ],
        ],
    ];
}
