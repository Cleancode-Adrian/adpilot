<?php

namespace App\Services\WebAudit;

use App\Models\WebAudit;

/**
 * Składa szczegółowy raport audytu w układzie „analizy strony”:
 * podsumowanie → zakres i metoda → sekcja dla każdej podstrony → cała strona → kod i technika → mobile a desktop
 * → plan działań → propozycje rozbudowy → checklista. Każda uwaga ma numer (np. 2.3), ważność (pilne / średnie / drobne)
 * i – jeśli trzeba – znacznik „do potwierdzenia”.
 */
class AuditReportBuilder
{
    public const SEV = ['pilne' => 'Pilne', 'srednie' => 'Średnie', 'drobne' => 'Drobne'];

    private const SEV_ORDER = ['pilne' => 0, 'srednie' => 1, 'drobne' => 2];

    private const PAGE_SUBTITLE = [
        'home' => 'Treść widoczna dla klienta, linki, obrazy i formularz',
        'offer' => 'Lista usług, ceny i linki do szczegółów',
        'detail' => 'Treść karty, przyciski zapisu i spójność z ofertą',
        'contact' => 'Formularz i dane kontaktowe',
        'about' => 'Informacje o firmie i zespole',
    ];

    public function build(WebAudit $audit, array $areas, ?array $inspection, ?array $ai, ?string $inspectionError = null): array
    {
        $findings = array_merge($inspection['findings'] ?? [], $ai['findings'] ?? [], $this->fromAreas($areas, $inspection));
        $pages = $inspection['pages'] ?? [];

        // --- sekcje
        $sections = [];
        foreach ($pages as $role => $p) {
            $sections[] = ['key' => $role, 'title' => $p['label'], 'subtitle' => self::PAGE_SUBTITLE[$role] ?? null, 'url' => $p['final_url'] ?? $p['url'],
                'findings' => array_values(array_filter($findings, fn ($f) => $f['section'] === 'page' && $f['page'] === $role))];
        }
        $sections[] = ['key' => 'site', 'title' => 'Cała strona: spójność i wiarygodność', 'subtitle' => 'Rzeczy, które dotyczą wszystkich podstron', 'url' => null,
            'findings' => array_values(array_filter($findings, fn ($f) => $f['section'] === 'page' && ($f['page'] === 'site' || ! isset($pages[$f['page']]))))];
        $sections[] = ['key' => 'code', 'title' => 'Kod i technika', 'subtitle' => 'Rzeczy widoczne w kodzie, a nie na ekranie: pomiar, zgody, bezpieczeństwo, szybkość, SEO techniczne', 'url' => null,
            'findings' => array_values(array_filter($findings, fn ($f) => $f['section'] === 'code'))];
        $sections[] = ['key' => 'perf', 'title' => 'Wydajność i szybkość', 'subtitle' => 'Google PageSpeed na telefonie i komputerze, obrazy, skrypty i czcionki, serwer i pamięć podręczna', 'url' => null,
            'findings' => array_values(array_filter($findings, fn ($f) => $f['section'] === 'perf'))];
        $sections[] = ['key' => 'mobile', 'title' => 'Telefon a komputer', 'subtitle' => 'Wnioski z budowy kodu – punkty oznaczone „do potwierdzenia” warto sprawdzić na telefonie', 'url' => null,
            'findings' => array_values(array_filter($findings, fn ($f) => $f['section'] === 'mobile'))];

        $order = [
            'code' => ['Pomiar i zgody (RODO)', 'Bezpieczeństwo', 'Kod strony', 'Język i teksty w kodzie', 'Dostępność', 'SEO techniczne'],
            'perf' => ['Wyniki Google PageSpeed', 'Możliwości przyspieszenia (Google PageSpeed)', 'Obrazy do optymalizacji', 'Serwer i pamięć podręczna', 'Skrypty, style i czcionki'],
        ];
        $num = 0;
        $all = [];
        foreach ($sections as &$s) {
            $num++;
            $s['num'] = $num;
            $s['groups'] = $this->groups($s['findings'], $order[$s['key']] ?? []);
            $i = 0;
            foreach ($s['groups'] as &$g) {
                foreach ($g['findings'] as &$f) {
                    $f['id'] = $num.'.'.(++$i);
                    $all[] = $f + ['section_title' => $s['title']];
                }
                unset($f);
            }
            unset($g);
            $s['counts'] = $this->counts(array_merge(...array_map(fn ($g) => $g['findings'], $s['groups']) ?: [[]]));
            unset($s['findings']);
        }
        unset($s);

        $counts = $this->counts($all);
        $proposals = $this->proposals($ai, $inspection);

        return [
            'version' => 2,
            'generated_at' => now()->toIso8601String(),
            'ai_model' => $ai['model'] ?? null,
            'company' => $audit->name ?: ($ai['company']['name'] ?? null),
            'industry' => $ai['company']['industry'] ?? null,
            'summary' => $ai['summary'] ?? $this->fallbackSummary($counts, count($pages), $all),
            'strengths' => array_values(array_filter($ai['strengths'] ?? $this->fallbackStrengths($areas))),
            'pages' => array_values(array_map(fn ($p) => ['role' => $p['role'], 'label' => $p['label'], 'url' => $p['final_url'] ?? $p['url'], 'title' => $p['title'] ?? null,
                'ok' => $p['ok'], 'status' => $p['status'], 'outline' => $this->outline($p['headings'] ?? [])], $pages)),
            'performance' => [
                'mobile' => $this->psSummary($areas['speed']['pagespeed'] ?? null),
                'desktop' => $this->psSummary($areas['speed']['desktop'] ?? null),
                'avg_ms' => $areas['speed']['avg_ms'] ?? null,
                'images' => $inspection['performance']['images'] ?? [],
                'pages' => $inspection['performance']['pages'] ?? [],
                'totals' => $inspection['performance']['totals'] ?? null,
            ],
            'inspection_error' => $inspectionError,
            'sections' => array_values(array_filter($sections, fn ($s) => $s['groups'] || isset($pages[$s['key']]))),
            'plan' => [
                'now' => $this->planItems($all, 'pilne'),
                'month' => $this->planItems($all, 'srednie'),
                'later' => $this->planItems($all, 'drobne'),
            ],
            'proposals' => $proposals,
            'checklist' => $this->checklist($ai, $all, $inspection),
            'counts' => $counts,
            'verify_count' => count(array_filter($all, fn ($f) => $f['verify'])),
        ];
    }

    // ------------------------------------------------------------------ dziedziny automatyczne → uwagi

    private function fromAreas(array $areas, ?array $inspection): array
    {
        $out = [];
        $add = function (string $group, string $sev, string $title, string $text, ?string $fix, array $urls = [], ?string $evidence = null, bool $verify = false) use (&$out) {
            $out[] = ['page' => 'site', 'section' => 'code', 'group' => $group, 'sev' => $sev, 'title' => $title, 'text' => $text, 'fix' => $fix,
                'evidence' => $evidence, 'urls' => array_slice($urls, 0, 5), 'verify' => $verify, 'source' => 'area'];
        };
        $inspected = array_column($inspection['findings'] ?? [], 'title');
        $hasViewportFinding = (bool) array_filter($inspected, fn ($t) => str_contains($t, 'viewport'));

        // Pomiar i zgody
        foreach ($areas['tracking']['checks'] ?? [] as $c) {
            if (! in_array($c['status'], ['fail', 'warn'], true) || in_array($c['group'], ['Strona i SEO', 'Szybkość'], true) || in_array($c['key'], ['https', 'tel_links'], true)) {
                continue;
            }
            $sev = $c['status'] === 'fail'
                ? (in_array($c['key'], ['cmp', 'consent_mode', 'ga4_duplicate'], true) ? 'pilne' : 'srednie')
                : (in_array($c['key'], ['cmp', 'lead_event', 'forms', 'consent_v2_params', 'gtm_multi', 'ga4_multi'], true) ? 'srednie' : 'drobne');
            $title = match ($c['key']) {
                'cmp' => $c['status'] === 'fail' ? 'Brak banera cookies (zgód RODO)' : 'Baner cookies wymaga sprawdzenia',
                'consent_mode' => 'Brak Google Consent Mode v2',
                default => $c['label'],
            };
            $text = $c['details'];
            if ($c['key'] === 'cmp' && $c['status'] === 'fail') {
                $text .= ' Jeśli baner nie jest dodawany innym skryptem, strona może zbierać dane analityczne i reklamowe bez zgody – ryzyko RODO.';
            }
            $add('Pomiar i zgody (RODO)', $sev, $title, $text, $c['fix'] ?? null, [], null, $c['key'] === 'cmp');
        }

        // Bezpieczeństwo – drobne nagłówki zbiorczo
        $minor = [];
        foreach ($areas['security']['checks'] ?? [] as $c) {
            if (! in_array($c['status'], ['fail', 'warn'], true)) {
                continue;
            }
            if ($c['status'] === 'fail') {
                $add('Bezpieczeństwo', ($c['weight'] ?? 3) >= 6 ? 'pilne' : 'srednie', $c['label'], $c['details'], $c['fix']);
            } elseif (in_array($c['key'], ['xmlrpc', 'wp_users', 'php_version', 'ssl_expiry'], true)) {
                $add('Bezpieczeństwo', 'srednie', $c['label'], $c['details'], $c['fix']);
            } else {
                $minor[] = $c;
            }
        }
        if ($minor) {
            $add('Bezpieczeństwo', 'drobne', 'Brakujące nagłówki bezpieczeństwa i ujawnione wersje oprogramowania',
                'Serwer nie wysyła części standardowych zabezpieczeń albo pokazuje wersje oprogramowania. Brakuje lub do poprawy: '.implode(', ', array_column($minor, 'label')).'. Każda rzecz z osobna jest drobna, razem ułatwiają ataki.',
                'Ustawić w konfiguracji serwera lub wtyczce bezpieczeństwa (np. Really Simple SSL, Solid Security) – to kilkanaście minut pracy.',
                [], implode("\n", array_map(fn ($c) => $c['details'], $minor)));
        }

        // Szybkość: wynik ogólny (telefon i komputer) + możliwości przyspieszenia z Lighthouse
        $perf = function (string $group, string $sev, string $title, string $text, ?string $fix, ?string $evidence = null) use (&$out) {
            $out[] = ['page' => 'site', 'section' => 'perf', 'group' => $group, 'sev' => $sev, 'title' => $title, 'text' => $text, 'fix' => $fix,
                'evidence' => $evidence, 'urls' => [], 'verify' => false, 'source' => 'area'];
        };
        $speed = $areas['speed'] ?? [];
        $ps = $speed['pagespeed'] ?? null;
        $desk = $speed['desktop'] ?? null;
        $score = $speed['score'] ?? null;
        $s1 = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '');
        if ($score !== null && $score < 90) {
            $sev = $score < 40 ? 'pilne' : ($score < 70 ? 'srednie' : 'drobne');
            $metrics = $ps ? 'Google PageSpeed na telefonie: '.$ps['performance'].'/100'.($desk ? ', na komputerze: '.$desk['performance'].'/100' : '').'. Główna treść pojawia się po '.$s1($ps['lcp_s']).' s (cel: do 2,5 s)'
                .(isset($ps['fcp_s']) ? ', pierwszy element po '.$s1($ps['fcp_s']).' s' : '').', strona jest zablokowana przez '.number_format($ps['tbt_ms'], 0, ',', ' ').' ms (cel: do 200 ms)'
                .(! empty($ps['total_kb']) ? ', waży '.$s1($ps['total_kb'] / 1024).' MB' : '').'.'
                : 'Średni czas odpowiedzi serwera: '.($speed['avg_ms'] ?? '–').' ms (cel: poniżej 600 ms).';
            $perf('Wyniki Google PageSpeed', $sev, $score < 70 ? 'Strona ładuje się wolno na telefonie' : 'Szybkość do poprawy',
                $metrics.' Wolna strona to mniej zapytań i droższe reklamy – część osób rezygnuje, zanim zobaczy ofertę.'.(! empty($ps['lcp_element']) ? ' Największy element na pierwszym ekranie: „'.mb_strimwidth($ps['lcp_element'], 0, 80, '…').'”.' : ''),
                'Szczegóły i kolejność prac – w punktach poniżej.');
        }
        if ($ps && ($ps['cls'] ?? 0) > 0.1) {
            $perf('Wyniki Google PageSpeed', $ps['cls'] > 0.25 ? 'srednie' : 'drobne', 'Treść „skacze” podczas ładowania (CLS '.str_replace('.', ',', (string) $ps['cls']).')',
                'Elementy przesuwają się, gdy doczytują się obrazki, czcionki lub banery – klient klika nie to, co chciał. Dobry wynik to poniżej 0,1.',
                'Podać wymiary obrazków i ramek, zarezerwować miejsce na baner cookies i widżety, wczytywać czcionki z font-display: swap.');
        }
        foreach ($ps['opportunities'] ?? [] as $o) {
            $ms = $o['savings_ms'];
            $sev = $ms >= 2500 ? 'pilne' : ($ms >= 800 || $o['savings_kb'] >= 500 ? 'srednie' : 'drobne');
            $save = array_filter([$ms >= 100 ? 'ok. '.$s1($ms / 1000).' s' : null, $o['savings_kb'] >= 20 ? $o['savings_kb'].' KB' : null]);
            $perf('Możliwości przyspieszenia (Google PageSpeed)', $sev, rtrim($o['title'], '.'),
                trim($o['description'].($save ? ' Szacowana oszczędność na telefonie: '.implode(' i ', $save).'.' : ($o['display'] ? ' Wynik: '.$o['display'].'.' : ''))),
                null, $o['items'] ? implode("\n", array_map(fn ($i) => $i['url'].(isset($i['wasted_kb']) ? ' – do oszczędzenia '.$i['wasted_kb'].' KB' : (isset($i['kb']) ? ' – '.$i['kb'].' KB' : '')).(isset($i['ms']) ? ', '.$i['ms'].' ms' : ''), array_slice($o['items'], 0, 4))) : null);
        }

        // Dostępność i dobre praktyki z Lighthouse (np. kontrast tekstu – nie da się go sprawdzić bez przeglądarki)
        foreach ($ps['accessibility_fails'] ?? [] as $f) {
            $add('Dostępność', in_array($f['id'], ['color-contrast', 'button-name', 'link-name', 'label', 'image-alt'], true) && $f['count'] >= 3 ? 'srednie' : 'drobne', rtrim($f['title'], '.'),
                trim($f['description'].($f['count'] ? ' Elementów: '.$f['count'].'.' : '')), null, [], $f['examples'] ? implode("\n", $f['examples']) : null);
        }
        foreach ($ps['best_practices_fails'] ?? [] as $f) {
            $add('Kod strony', $f['id'] === 'errors-in-console' && $f['count'] >= 3 ? 'srednie' : 'drobne', rtrim($f['title'], '.'),
                trim($f['description'].($f['count'] ? ' Elementów: '.$f['count'].'.' : '')), null, [], $f['examples'] ? implode("\n", $f['examples']) : null);
        }

        // SEO techniczne – najważniejsze, reszta zbiorczo (bez zmian treści pod SEO)
        $issues = array_values(array_filter($areas['seo']['issues'] ?? [], fn ($i) => ! ($hasViewportFinding && $i['code'] === 'no_viewport')));
        // Brak / kilka H1 na wszystkich sprawdzonych podstronach – do sekcji o strukturze nagłówków
        if ($inspection) {
            foreach (array_filter($issues, fn ($i) => in_array($i['code'], ['missing_h1', 'multiple_h1'], true)) as $i) {
                $out[] = ['page' => 'site', 'section' => 'page', 'group' => 'Struktura nagłówków', 'sev' => $i['code'] === 'missing_h1' ? 'srednie' : 'drobne',
                    'title' => $i['title'].' – '.$i['count'].' z '.($areas['seo']['pages'] ?? '?').' sprawdzonych podstron', 'text' => $i['description'], 'fix' => $i['action'],
                    'evidence' => implode("\n", array_slice($i['urls'], 0, 5)), 'urls' => [], 'verify' => false, 'source' => 'area'];
            }
            $issues = array_values(array_filter($issues, fn ($i) => ! in_array($i['code'], ['missing_h1', 'multiple_h1'], true)));
        }
        $map = ['critical' => 'pilne', 'high' => 'srednie', 'medium' => 'drobne', 'low' => 'drobne'];
        $main = array_values(array_filter($issues, fn ($i) => in_array($i['severity'], ['critical', 'high'], true)));
        $rest = array_values(array_filter($issues, fn ($i) => ! in_array($i['severity'], ['critical', 'high'], true)));
        foreach ($main as $i) {
            $add('SEO techniczne', $map[$i['severity']], $i['title'], $i['description'].($i['scope'] === 'pages' ? ' Dotyczy '.$i['count'].' '.($i['count'] === 1 ? 'adresu' : 'adresów').'.' : ''), $i['action'], $i['urls'] ?? []);
        }
        foreach (array_slice($rest, 0, 6) as $i) {
            $add('SEO techniczne', 'drobne', $i['title'], $i['description'].($i['scope'] === 'pages' ? ' Dotyczy '.$i['count'].' '.($i['count'] === 1 ? 'adresu' : 'adresów').'.' : ''), $i['action'], $i['urls'] ?? []);
        }
        if (count($rest) > 6) {
            $add('SEO techniczne', 'drobne', 'Pozostałe drobne uwagi techniczne SEO',
                implode('; ', array_map(fn ($i) => $i['title'].($i['scope'] === 'pages' ? ' ('.$i['count'].')' : ''), array_slice($rest, 6))).'.', 'Do uporządkowania przy okazji innych prac.');
        }

        return $out;
    }

    // ------------------------------------------------------------------ porządkowanie

    /** Grupy w sekcji: najpierw grupy z pilnymi uwagami, w grupie od najważniejszych. */
    private function groups(array $findings, array $order): array
    {
        $groups = [];
        foreach ($findings as $f) {
            $groups[$f['group']][] = $f;
        }
        foreach ($groups as &$g) {
            usort($g, fn ($a, $b) => self::SEV_ORDER[$a['sev']] <=> self::SEV_ORDER[$b['sev']]);
        }
        unset($g);
        $keys = array_keys($groups);
        usort($keys, function ($a, $b) use ($groups, $order) {
            if ($order) {
                $ia = array_search($a, $order, true);
                $ib = array_search($b, $order, true);

                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            }
            $worst = fn ($k) => min(array_map(fn ($f) => self::SEV_ORDER[$f['sev']], $groups[$k]));

            return $worst($a) <=> $worst($b);
        });

        return array_map(fn ($k) => ['title' => $k, 'findings' => $groups[$k]], $keys);
    }

    private function counts(array $findings): array
    {
        $c = ['pilne' => 0, 'srednie' => 0, 'drobne' => 0];
        foreach ($findings as $f) {
            $c[$f['sev']]++;
        }

        return $c;
    }

    private function planItems(array $all, string $sev): array
    {
        $items = array_values(array_filter($all, fn ($f) => $f['sev'] === $sev));

        return array_map(fn ($f) => [
            'id' => $f['id'],
            'title' => $f['title'],
            'where' => $f['section_title'],
            'who' => in_array($f['section'], ['code', 'perf', 'mobile'], true) ? 'technika' : 'treść',
            'verify' => $f['verify'],
        ], $items);
    }

    /** Spis nagłówków podstrony z zaznaczonymi problemami (przeskok poziomu, pusty, kilka H1). */
    private function outline(array $headings): array
    {
        $out = [];
        $prev = 0;
        $h1 = 0;
        foreach (array_slice($headings, 0, 70) as $h) {
            $problem = null;
            if ($h['empty']) {
                $problem = 'pusty';
            } elseif ($h['level'] === 1 && ++$h1 > 1) {
                $problem = 'kolejny H1';
            } elseif (($h['zone'] ?? 'main') === 'main' && $prev && $h['level'] > $prev + 1) {
                $problem = 'przeskok z H'.$prev;
            }
            if (! $h['empty'] && ($h['zone'] ?? 'main') === 'main') {
                $prev = $h['level'];
            }
            $out[] = ['level' => $h['level'], 'text' => mb_strimwidth($h['text'] ?: '(pusty)', 0, 110, '…'), 'zone' => $h['zone'] ?? 'main', 'problem' => $problem];
        }

        return $out;
    }

    private function psSummary(?array $ps): ?array
    {
        return $ps ? array_intersect_key($ps, array_flip(['performance', 'accessibility', 'best_practices', 'seo', 'fcp_s', 'lcp_s', 'si_s', 'tbt_ms', 'cls', 'ttfb_ms', 'total_kb', 'requests', 'dom'])) : null;
    }

    // ------------------------------------------------------------------ propozycje i checklista

    private function proposals(?array $ai, ?array $inspection): array
    {
        if (! empty($ai['proposals'])) {
            return array_values($ai['proposals']);
        }
        $pages = $inspection['pages'] ?? [];
        $phone = $inspection['facts']['phones'][0] ?? null;
        $hasForm = (bool) array_filter($pages, fn ($p) => ! empty($p['forms']));
        $out = [];
        if (isset($pages['offer'])) {
            $out[] = ['title' => 'Kalkulator „Dobierz usługę i sprawdź koszt”', 'what' => 'Klient w kilku krokach wybiera usługę i parametry, a kalkulator na podstawie cennika pokazuje orientacyjny koszt i od razu wysyła zgłoszenie z kompletem danych.',
                'steps' => ['Wybór usługi (pełna lista z oferty).', 'Parametry wpływające na cenę (zakres, częstotliwość, wielkość).', 'Wynik: cena od–do i link do szczegółów usługi.', 'Formularz z już wypełnionym wyborem.'],
                'benefit' => 'Odpowiada na pierwsze pytanie klienta („ile to kosztuje?”), a zgłoszenia przychodzą z informacją, czego dokładnie dotyczą.',
                'needs_from_client' => 'Cennik i zasady wyceny (rabaty, dopłaty).', 'implementation' => 'Formularz z polami obliczeniowymi w WordPress (np. Calculated Fields Form, Fluent Forms Pro lub Gravity Forms); wynik trafia mailem do firmy.', 'effort' => 'M'];
        }
        if ($hasForm && isset($pages['offer'])) {
            $out[] = ['title' => 'Przenoszenie wybranej oferty do formularza', 'what' => 'Przycisk zapisu/zamówienia na stronie usługi otwiera formularz z już wybraną usługą (np. /kontakt/?usluga=…).',
                'steps' => ['Dodanie parametru do linków na stronach usług.', 'Odczyt parametru w formularzu i ustawienie pola.', 'Uzupełnienie listy wyboru o wszystkie usługi z oferty.'],
                'benefit' => 'Klient nie wybiera drugi raz tego, co już wybrał – mniej porzuconych zgłoszeń.', 'needs_from_client' => 'Brak.',
                'implementation' => 'Parametr w adresie + domyślna wartość pola (obsługuje to większość wtyczek formularzy).', 'effort' => 'S'];
        }
        $out[] = ['title' => 'Rezerwacja rozmowy lub wizyty w kalendarzu', 'what' => 'Zamiast czekać na telefon, klient od razu wybiera wolny termin krótkiej rozmowy lub wizyty.',
            'steps' => ['Wybór usługi.', 'Wybór wolnego terminu w kalendarzu (godziny pracy firmy).', 'Potwierdzenie i przypomnienie SMS/e-mail.'],
            'benefit' => 'Kalendarz zbiera zgłoszenia także wieczorem i w weekend i usuwa etap „oddzwonimy”.', 'needs_from_client' => 'Dostępność osób i czas trwania spotkania.',
            'implementation' => 'Wtyczka rezerwacji (np. Amelia, Bookly) albo zewnętrzny kalendarz osadzony na stronie.', 'effort' => 'M'];
        $out[] = ['title' => 'Przyklejony pasek kontaktu na telefonie', 'what' => 'Na dole ekranu telefonu stały pasek z przyciskami „Zadzwoń” i „Napisz”; na komputerze – przycisk do formularza.',
            'steps' => ['Pasek widoczny tylko na ekranach mobilnych.', 'Przycisk „Zadzwoń” – '.($phone ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : 'link tel:').'.', 'Przycisk „Napisz” – formularz, SMS lub WhatsApp.'],
            'benefit' => 'Najprostszy sposób na więcej telefonów z wersji mobilnej, z której przychodzi zwykle większość ruchu.', 'needs_from_client' => 'Brak.',
            'implementation' => 'Element Elementora (lub motywu) widoczny tylko na mobile.', 'effort' => 'S'];

        return $out;
    }

    private function checklist(?array $ai, array $all, ?array $inspection): array
    {
        $items = array_values($ai['checklist'] ?? []);
        foreach ($all as $f) {
            if ($f['verify'] && in_array($f['section'], ['mobile', 'code', 'perf'], true)) {
                $items[] = 'Do potwierdzenia ('.$f['id'].'): '.rtrim($f['title'], '.').'?';
            }
        }
        $defaults = [
            'Czy przy pierwszym wejściu (tryb incognito) pojawia się baner cookies i czy da się odrzucić zgody jednym kliknięciem?',
            'Czy po wysłaniu formularza pojawia się potwierdzenie i czy wiadomość dociera na właściwą skrzynkę?',
            'Czy na telefonie menu rozwija podmenu po dotknięciu i da się je zamknąć?',
            'Czy na telefonie numer telefonu jest widoczny bez przewijania i czy dotknięcie go dzwoni?',
            'Czy na telefonie żaden element (baner, obrazek, tabela, mapa) nie wychodzi poza ekran?',
            'Czy długie tytuły kafelków i przyciski są czytelne na telefonie (nie są ucięte)?',
        ];
        foreach ($defaults as $d) {
            $items[] = $d;
        }
        $seen = [];
        $out = [];
        foreach ($items as $i) {
            $key = mb_strtolower(mb_substr(preg_replace('/[^\p{L}]/u', '', $i), 0, 40));
            $similar = (bool) array_filter(array_keys($seen), function ($k) use ($key) {
                similar_text($k, $key, $p);

                return $p > 70;
            });
            if (! $similar) {
                $seen[$key] = true;
                $out[] = $i;
            }
        }

        return array_slice($out, 0, 14);
    }

    private function fallbackSummary(array $counts, int $pages, array $all): string
    {
        $top = array_values(array_filter($all, fn ($f) => $f['sev'] === 'pilne'));
        $pl = fn (int $n, string $one, string $few, string $many) => $n.' '.($n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many));

        return 'Sprawdziliśmy '.($pages ? $pl($pages, 'podstronę', 'podstrony', 'podstron') : 'najważniejsze podstrony').' oraz kod, pomiar, bezpieczeństwo i szybkość strony. '
            .'Znaleźliśmy '.$pl($counts['pilne'], 'pilną sprawę', 'pilne sprawy', 'pilnych spraw').', '.$pl($counts['srednie'], 'średnią', 'średnie', 'średnich')
            .' i '.$pl($counts['drobne'], 'drobną uwagę', 'drobne uwagi', 'drobnych uwag').'.'
            .($top ? ' Najpierw warto zająć się punktem: '.mb_strtolower(mb_substr($top[0]['title'], 0, 1)).mb_substr($top[0]['title'], 1).'.' : '');
    }

    private function fallbackStrengths(array $areas): array
    {
        $phrases = ['https' => 'Szyfrowane połączenie (HTTPS)', 'ssl_expiry' => 'Ważny certyfikat SSL', 'ga4' => 'Google Analytics 4', 'gtm' => 'Menedżer tagów Google',
            'meta_pixel' => 'Piksel Meta', 'cmp' => 'Baner zgód (cookies)', 'consent_mode' => 'Google Consent Mode v2', 'forms' => 'Formularz kontaktowy na stronie głównej',
            'tel_links' => 'Klikalny numer telefonu', 'dir_listing' => 'Katalogi serwera nie są publicznie widoczne'];
        $ok = [];
        foreach (['security', 'tracking'] as $k) {
            foreach ($areas[$k]['checks'] ?? [] as $c) {
                if ($c['status'] === 'ok' && isset($phrases[$c['key']])) {
                    $ok[$phrases[$c['key']]] = true;
                }
            }
        }

        return array_slice(array_keys($ok), 0, 6);
    }
}
