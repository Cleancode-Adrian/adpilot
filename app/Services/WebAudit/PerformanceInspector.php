<?php

namespace App\Services\WebAudit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Wydajność z kodu i nagłówków najważniejszych podstron (niezależnie od Google PageSpeed):
 * - obrazy: waga, wymiary, format, brak WebP, PNG zamiast JPG, brak lazy loading / wymiarów / srcset, pamięć podręczna, błędy 404,
 * - skrypty, style i czcionki: liczba plików, skrypty blokujące wczytywanie, zewnętrzne usługi, zbędne skrypty WordPressa,
 * - serwer: czas odpowiedzi (TTFB), kompresja, cache strony, waga HTML i liczba elementów.
 */
class PerformanceInspector
{
    private const UA = 'Mozilla/5.0 (compatible; CC-SiteAudit/1.0)';

    private const MAX_IMAGES = 40;

    private array $findings = [];

    /**
     * @param  array<string, array>  $pages  zrzuty z PageInspector (z polami raw, final_url, label, headers, ttfb_ms)
     * @return array{findings: list<array>, images: list<array>, pages: list<array>, totals: array}
     */
    public function inspect(array $pages): array
    {
        $this->findings = [];
        $images = [];
        $pageStats = [];

        foreach ($pages as $role => $p) {
            $stats = $this->pageStats($role, $p, $images);
            $pageStats[] = $stats;
        }
        $measured = $this->measureImages($images);

        $this->imageFindings($measured, $pageStats);
        $this->assetFindings($pages, $pageStats);
        $this->serverFindings($pages, $pageStats);

        $heavy = array_values(array_filter($measured, fn ($i) => ($i['kb'] ?? 0) > 0));
        usort($heavy, fn ($a, $b) => $b['kb'] <=> $a['kb']);

        return [
            'findings' => $this->findings,
            'images' => array_slice(array_map(fn ($i) => array_intersect_key($i, array_flip(['url', 'kb', 'width', 'height', 'format', 'pages', 'saving_kb', 'issues'])), $heavy), 0, 15),
            'pages' => array_map(fn ($s) => array_diff_key($s, array_flip(['head_blocking_list', 'third_party_list', 'img_nodes'])), $pageStats),
            'totals' => [
                'images_checked' => count($measured),
                'images_kb' => array_sum(array_column($measured, 'kb')),
                'saving_kb' => array_sum(array_column($measured, 'saving_kb')),
            ],
        ];
    }

    // ------------------------------------------------------------------ zbieranie

    private function pageStats(string $role, array $p, array &$images): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$p['raw'], LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $host = $this->bare((string) parse_url($p['final_url'], PHP_URL_HOST));

        // Obrazy <img> i tła z atrybutu style
        $order = 0;
        foreach ($x->query('//img') as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('src');
            $abs = $this->absolute($src, $p['final_url']);
            if (! $abs || str_ends_with(strtolower((string) parse_url($abs, PHP_URL_PATH)), '.svg')) {
                continue;
            }
            $order++;
            $lazy = strtolower($img->getAttribute('loading')) === 'lazy' || $img->hasAttribute('data-src') || $img->hasAttribute('data-lazy-src') || str_contains($img->getAttribute('class'), 'lazy');
            $images[$abs] ??= ['url' => $abs, 'pages' => [], 'tags' => []];
            $images[$abs]['pages'][$p['label']] = true;
            $images[$abs]['tags'][] = [
                'order' => $order,
                'lazy' => $lazy,
                'sized' => $img->hasAttribute('width') && $img->hasAttribute('height'),
                'srcset' => $img->hasAttribute('srcset') || $img->hasAttribute('data-srcset'),
                'width_attr' => (int) $img->getAttribute('width'),
                'priority' => strtolower($img->getAttribute('fetchpriority')) === 'high',
            ];
        }
        foreach ($x->query('//*[@style[contains(., "url(")]]') as $el) {
            /** @var DOMElement $el */
            if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $el->getAttribute('style'), $m)) {
                foreach ($m[1] as $u) {
                    $abs = $this->absolute(html_entity_decode($u), $p['final_url']);
                    if ($abs && preg_match('/\.(jpe?g|png|webp|avif|gif)(\?|$)/i', $abs)) {
                        $images[$abs] ??= ['url' => $abs, 'pages' => [], 'tags' => []];
                        $images[$abs]['pages'][$p['label']] = true;
                        $images[$abs]['background'] = true;
                    }
                }
            }
        }

        // Skrypty i style
        $scripts = $x->query('//script[@src]');
        $headBlocking = [];
        $thirdParty = [];
        $plugins = [];
        foreach ($scripts as $s) {
            /** @var DOMElement $s */
            $src = $s->getAttribute('src');
            $abs = $this->absolute($src, $p['final_url']) ?? $src;
            $inHead = $x->query('ancestor::head', $s)->length > 0;
            if ($inHead && ! $s->hasAttribute('async') && ! $s->hasAttribute('defer') && strtolower($s->getAttribute('type')) !== 'module') {
                $headBlocking[] = $this->shortName($abs);
            }
            $h = $this->bare((string) parse_url($abs, PHP_URL_HOST));
            if ($h && $h !== $host) {
                $thirdParty[$h] = true;
            }
            if (preg_match('#/wp-content/plugins/([^/]+)/#', $abs, $pm)) {
                $plugins[$pm[1]] = true;
            }
        }
        $styles = $x->query('//link[translate(@rel,"STYLESHEET","stylesheet")="stylesheet"][@href]');
        foreach ($styles as $l) {
            /** @var DOMElement $l */
            $abs = $this->absolute($l->getAttribute('href'), $p['final_url']) ?? '';
            if (preg_match('#/wp-content/plugins/([^/]+)/#', $abs, $pm)) {
                $plugins[$pm[1]] = true;
            }
            $h = $this->bare((string) parse_url($abs, PHP_URL_HOST));
            if ($h && $h !== $host && ! str_contains($h, 'fonts.googleapis')) {
                $thirdParty[$h] = true;
            }
        }
        foreach ($x->query('//iframe[@src]') as $f) {
            $h = $this->bare((string) parse_url((string) $this->absolute($f->getAttribute('src'), $p['final_url']), PHP_URL_HOST));
            if ($h && $h !== $host) {
                $thirdParty[$h] = true;
            }
        }
        $iframesEager = [];
        foreach ($x->query('//iframe[@src]') as $f) {
            /** @var DOMElement $f */
            if (strtolower($f->getAttribute('loading')) !== 'lazy' && ! $f->hasAttribute('data-src') && preg_match('#youtube|youtu\.be|vimeo|google\.[a-z.]+/maps|maps\.google#i', $f->getAttribute('src'))) {
                $iframesEager[] = preg_match('#maps#i', $f->getAttribute('src')) ? 'mapa Google' : 'film';
            }
        }

        $fontFamilies = [];
        foreach ($x->query('//link[contains(@href,"fonts.googleapis.com")]/@href') as $href) {
            if (preg_match_all('/family=([^&:]+)/', html_entity_decode($href->nodeValue), $fm)) {
                foreach ($fm[1] as $f) {
                    foreach (explode('|', urldecode($f)) as $fam) {
                        $fontFamilies[trim(explode(':', $fam)[0])] = true;
                    }
                }
            }
        }
        preg_match_all('/@font-face/i', $p['raw'], $ff);

        $raw = $p['raw'];
        $wpExtras = array_values(array_filter([
            str_contains($raw, 'wp-emoji-release') || str_contains($raw, '_wpemojiSettings') ? 'emoji WordPressa (wp-emoji)' : null,
            str_contains($raw, 'jquery-migrate') ? 'jquery-migrate' : null,
            str_contains($raw, 'dashicons.min.css') ? 'ikony panelu (dashicons)' : null,
            str_contains($raw, 'wp-embed') ? 'wp-embed' : null,
            preg_match('#/wp-includes/css/dist/block-library/style(\.min)?\.css#', $raw) && str_contains($raw, 'elementor') ? 'style bloków Gutenberga przy stronie z Elementora' : null,
        ]));
        $cacheSignature = $this->cacheSignature($raw, $p['headers'] ?? []);

        return [
            'role' => $role,
            'label' => $p['label'],
            'url' => $p['final_url'],
            'ttfb_ms' => $p['ttfb_ms'] ?? null,
            'html_kb' => (int) round(strlen($raw) / 1024),
            'dom' => $x->query('//*')->length,
            'scripts' => $scripts->length,
            'styles' => $styles->length,
            'inline_css_kb' => (int) round(array_sum(array_map(fn ($n) => strlen($n->textContent), iterator_to_array($x->query('//style')))) / 1024),
            'head_blocking' => count($headBlocking),
            'head_blocking_list' => array_slice($headBlocking, 0, 8),
            'third_party' => count($thirdParty),
            'third_party_list' => array_keys($thirdParty),
            'plugins' => count($plugins),
            'fonts' => array_keys($fontFamilies),
            'font_faces' => count($ff[0]),
            'iframes_eager' => $iframesEager,
            'wp_extras' => $wpExtras,
            'compressed' => ($p['headers']['content-encoding'] ?? $p['headers']['x-encoded-content-encoding'] ?? '') ?: null,
            'page_cache' => $cacheSignature,
            'images' => $order,
        ];
    }

    /** Pobiera nagłówki (rozmiar, typ, cache) i początek pliku (wymiary) dla obrazów. */
    private function measureImages(array $images): array
    {
        $images = array_slice($images, 0, self::MAX_IMAGES, true);
        if (! $images) {
            return [];
        }
        $urls = array_keys($images);
        $responses = Http::pool(function (Pool $pool) use ($urls) {
            foreach ($urls as $u) {
                // pierwsze 128 KB wystarczą, by odczytać wymiary; pełną wagę podaje Content-Range / Content-Length
                $pool->as($u)->withUserAgent(self::UA)->timeout(15)->connectTimeout(8)->withHeaders(['Range' => 'bytes=0-131071'])->get($u);
            }
        });
        $out = [];
        foreach ($images as $u => $img) {
            $r = $responses[$u] ?? null;
            if (! $r instanceof Response) {
                continue;
            }
            $status = $r->status();
            $total = null;
            if (preg_match('#/(\d+)$#', (string) $r->header('Content-Range'), $m)) {
                $total = (int) $m[1];
            } elseif ($status === 200) {
                $total = (int) ($r->header('Content-Length') ?: strlen($r->body()));
            }
            $type = strtolower(explode(';', (string) $r->header('Content-Type'))[0]);
            $format = match (true) {
                str_contains($type, 'webp') => 'WebP', str_contains($type, 'avif') => 'AVIF', str_contains($type, 'png') => 'PNG',
                str_contains($type, 'gif') => 'GIF', str_contains($type, 'jpeg') || str_contains($type, 'jpg') => 'JPG',
                default => strtoupper((string) pathinfo((string) parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: '?',
            };
            $dims = @getimagesizefromstring($r->body()) ?: null;
            $kb = $total ? (int) round($total / 1024) : 0;
            $cc = strtolower((string) $r->header('Cache-Control'));
            $maxAge = preg_match('/max-age=(\d+)/', $cc, $mm) ? (int) $mm[1] : null;
            $tags = $img['tags'];

            $issues = [];
            $saving = 0;
            if ($status >= 400) {
                $issues[] = 'nie działa (błąd '.$status.')';
            }
            if (in_array($format, ['JPG', 'PNG'], true) && $kb > 60) {
                $saving = max($saving, (int) round($kb * ($format === 'PNG' ? 0.6 : 0.3)));
                $issues[] = 'format '.$format.' zamiast WebP';
            }
            if ($dims && $dims[0] > 2000) {
                $saving = max($saving, (int) round($kb * (1 - min(1, (1600 / $dims[0]) ** 2))));
                $issues[] = 'za duża rozdzielczość ('.$dims[0].'×'.$dims[1].' px)';
            }
            if ($format === 'PNG' && $kb > 200 && ! ($dims && $dims[0] < 600)) {
                $issues[] = 'zdjęcie zapisane jako PNG';
            }
            if ($kb > 300 && $tags && ! array_filter($tags, fn ($t) => $t['srcset'])) {
                $issues[] = 'jedna wersja dla telefonu i komputera (brak srcset)';
            }
            $out[] = [
                'url' => $u,
                'status' => $status,
                'kb' => $kb,
                'format' => $format,
                'width' => $dims[0] ?? null,
                'height' => $dims[1] ?? null,
                'max_age' => $maxAge,
                'pages' => array_keys($img['pages']),
                'tags' => $tags,
                'background' => $img['background'] ?? false,
                'saving_kb' => $saving,
                'issues' => $issues,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ uwagi

    private function imageFindings(array $imgs, array $pageStats): void
    {
        if (! $imgs) {
            return;
        }
        $kb = fn ($v) => $v >= 1024 ? number_format($v / 1024, 1, ',', '').' MB' : $v.' KB';
        $name = fn ($i) => urldecode(basename((string) parse_url($i['url'], PHP_URL_PATH)));

        $broken = array_filter($imgs, fn ($i) => $i['status'] >= 400);
        if ($broken) {
            $this->add('Obrazy do optymalizacji', 'srednie', 'Niedziałające obrazki',
                count($broken).' '.$this->pl(count($broken), 'obrazek zwraca', 'obrazki zwracają', 'obrazków zwraca').' błąd – na stronie w ich miejscu jest puste pole albo ikona błędu.',
                'Podmienić pliki albo usunąć odwołania.', implode("\n", array_map(fn ($i) => $i['url'].' ('.$i['status'].')', array_slice($broken, 0, 4))), array_column(array_slice($broken, 0, 5), 'url'));
        }

        $ok = array_filter($imgs, fn ($i) => $i['status'] < 400);
        $heavy = array_filter($ok, fn ($i) => $i['kb'] >= 250);
        usort($heavy, fn ($a, $b) => $b['kb'] <=> $a['kb']);
        if ($heavy) {
            $max = $heavy[0]['kb'];
            $sum = array_sum(array_column($heavy, 'kb'));
            $saving = array_sum(array_column($ok, 'saving_kb'));
            $this->add('Obrazy do optymalizacji', $max >= 1024 || $sum >= 3072 ? 'pilne' : 'srednie', 'Ciężkie zdjęcia ('.count($heavy).' '.$this->pl(count($heavy), 'plik', 'pliki', 'plików').' powyżej 250 KB)',
                'Najcięższe obrazki ważą razem '.$kb($sum).', największy '.$kb($max).'. Na telefonie przy słabszym zasięgu każde 1 MB to kilka sekund czekania – zdjęcia to zwykle największa część wagi strony.'
                .($saving > 100 ? ' Szacowana oszczędność po optymalizacji: ok. '.$kb($saving).'.' : ''),
                'Zmniejszyć zdjęcia do szerokości, w jakiej są wyświetlane (zwykle do 1600–1920 px), skompresować i zapisać jako WebP (np. wtyczka Converter for Media, EWWW, ShortPixel albo funkcja hostingu/CDN).',
                implode("\n", array_map(fn ($i) => $name($i).' – '.$kb($i['kb']).($i['width'] ? ', '.$i['width'].'×'.$i['height'].' px' : '').', '.$i['format'], array_slice($heavy, 0, 6))));
        }
        $huge = array_filter($ok, fn ($i) => ($i['width'] ?? 0) > 2560);
        if ($huge) {
            $this->add('Obrazy do optymalizacji', 'srednie', 'Zdjęcia w rozdzielczości prosto z aparatu',
                count($huge).' '.$this->pl(count($huge), 'obrazek ma', 'obrazki mają', 'obrazków ma').' ponad 2560 px szerokości (np. '.$name(reset($huge)).' – '.reset($huge)['width'].'×'.reset($huge)['height'].' px). Żaden ekran tego nie wyświetli, a telefon i tak pobiera cały plik.',
                'Przeskalować do maks. 1920 px (WordPress: Ustawienia → Media albo wtyczka do zmniejszania obrazów przy wgrywaniu).');
        }
        $old = array_filter($ok, fn ($i) => in_array($i['format'], ['JPG', 'PNG'], true) && $i['kb'] > 60);
        $modern = array_filter($ok, fn ($i) => in_array($i['format'], ['WebP', 'AVIF'], true));
        if (count($old) >= 3 && count($modern) < count($old)) {
            $this->add('Obrazy do optymalizacji', 'drobne', 'Zdjęcia w starszych formatach (JPG/PNG)',
                count($old).' '.$this->pl(count($old), 'zdjęcie jest', 'zdjęcia są', 'zdjęć jest').' w formacie JPG/PNG'.($modern ? ', a tylko '.count($modern).' w WebP/AVIF' : ' i żadne w WebP/AVIF').'. WebP przy tej samej jakości jest zwykle o 25–35% lżejszy (PNG ze zdjęciem – nawet kilka razy).',
                'Włączyć automatyczną konwersję do WebP (wtyczka lub hosting/CDN) – bez ręcznej podmiany zdjęć.');
        }
        $pngPhotos = array_filter($ok, fn ($i) => in_array('zdjęcie zapisane jako PNG', $i['issues'], true));
        if ($pngPhotos) {
            $this->add('Obrazy do optymalizacji', 'srednie', 'Zdjęcia zapisane jako PNG',
                'PNG nadaje się do logo i grafik z przezroczystością; zdjęcie w PNG waży kilka razy więcej niż w JPG/WebP. Np. '.$name(reset($pngPhotos)).' – '.$kb(reset($pngPhotos)['kb']).'.',
                'Zapisać zdjęcia jako WebP lub JPG.', implode("\n", array_map(fn ($i) => $name($i).' – '.$kb($i['kb']), array_slice($pngPhotos, 0, 4))));
        }

        // Atrybuty <img>: lazy loading, wymiary, priorytet głównego zdjęcia
        $tags = [];
        foreach ($ok as $i) {
            foreach ($i['tags'] as $t) {
                $tags[] = $t + ['kb' => $i['kb'], 'url' => $i['url']];
            }
        }
        $notLazy = array_filter($tags, fn ($t) => $t['order'] > 3 && ! $t['lazy'] && $t['kb'] > 30);
        if (count($notLazy) >= 3) {
            $this->add('Obrazy do optymalizacji', 'srednie', 'Obrazki niżej na stronie ładują się od razu',
                count($notLazy).' '.$this->pl(count($notLazy), 'obrazek', 'obrazki', 'obrazków').' poniżej pierwszego ekranu nie ma „leniwego ładowania” (loading="lazy") – przeglądarka pobiera je wszystkie, zanim klient w ogóle przewinie stronę.',
                'Włączyć lazy loading obrazów (WordPress robi to domyślnie – sprawdzić, czy motyw lub wtyczka go nie wyłącza; Elementor: Ustawienia → Wydajność).');
        }
        $lazyHero = array_filter($tags, fn ($t) => $t['order'] === 1 && $t['lazy'] && $t['kb'] > 50);
        if ($lazyHero) {
            $this->add('Obrazy do optymalizacji', 'srednie', 'Główne zdjęcie na górze strony ładuje się z opóźnieniem',
                'Pierwszy obraz na stronie (zwykle największy element w pierwszym ekranie) ma leniwe ładowanie – przeglądarka pobiera go na końcu, więc strona dłużej wygląda na pustą (gorszy wynik LCP).',
                'Wyłączyć lazy loading dla pierwszego zdjęcia i dodać mu fetchpriority="high".', $name(reset($lazyHero)));
        }
        $unsized = array_filter($tags, fn ($t) => ! $t['sized']);
        if (count($unsized) >= 4) {
            $this->add('Obrazy do optymalizacji', 'drobne', 'Obrazki bez podanych wymiarów',
                count($unsized).' '.$this->pl(count($unsized), 'obrazek nie ma', 'obrazki nie mają', 'obrazków nie ma').' w kodzie szerokości i wysokości – podczas ładowania treść „skacze”, bo przeglądarka nie wie, ile miejsca zarezerwować (wskaźnik CLS).',
                'Dodać atrybuty width i height (WordPress i Elementor robią to przy wstawianiu obrazów z biblioteki mediów).');
        }
        $noCache = array_filter($ok, fn ($i) => $i['max_age'] !== null && $i['max_age'] < 7 * 86400 || $i['max_age'] === null);
        if (count($noCache) >= max(3, (int) ceil(count($ok) * 0.5))) {
            $this->add('Serwer i pamięć podręczna', 'drobne', 'Obrazy bez długiej pamięci podręcznej',
                'Przy '.count($noCache).' z '.count($ok).' obrazów serwer nie pozwala przeglądarce zapamiętać pliku na dłużej (Cache-Control krótszy niż 7 dni albo brak). Powracający klient pobiera je za każdym razem od nowa.',
                'Ustawić długi czas cache dla plików statycznych (np. 1 rok) w .htaccess, wtyczce cache albo CDN.');
        }
    }

    private function assetFindings(array $pages, array $stats): void
    {
        $home = $stats[0] ?? null;
        if (! $home) {
            return;
        }
        $worst = fn (string $k) => array_reduce($stats, fn ($c, $s) => ! $c || $s[$k] > $c[$k] ? $s : $c);

        $b = $worst('head_blocking');
        if ($b['head_blocking'] >= 3) {
            $this->add('Skrypty, style i czcionki', $b['head_blocking'] >= 8 ? 'srednie' : 'drobne', 'Skrypty blokujące wyświetlenie strony',
                'Na podstronie „'.$b['label'].'” '.$this->pl($b['head_blocking'], 'skrypt', 'skrypty', 'skryptów').' w nagłówku kodu nie ma atrybutu async/defer – przeglądarka musi je pobrać i wykonać, zanim cokolwiek pokaże.',
                'Włączyć opóźnione ładowanie skryptów (np. WP Rocket, LiteSpeed Cache, Perfmatters – „Delay/Defer JavaScript”), zostawiając bez zmian tylko to, co niezbędne.',
                implode(', ', $b['head_blocking_list']));
        }
        $s = $worst('scripts');
        if ($s['scripts'] >= 25) {
            $this->add('Skrypty, style i czcionki', $s['scripts'] >= 40 ? 'srednie' : 'drobne', 'Dużo plików JavaScript',
                'Podstrona „'.$s['label'].'” ładuje '.$s['scripts'].' plików JS i '.$s['styles'].' plików CSS'.($s['plugins'] ? ' (pliki z '.$s['plugins'].' '.$this->pl($s['plugins'], 'wtyczki', 'wtyczek', 'wtyczek').')' : '').'. Każdy plik to osobne zapytanie i praca dla telefonu.',
                'Przejrzeć wtyczki (usunąć nieużywane), ładować skrypty tylko tam, gdzie są potrzebne (np. formularz tylko na kontakcie), połączyć/zminifikować pliki.');
        }
        $t = $worst('third_party');
        if ($t['third_party'] >= 6) {
            $this->add('Skrypty, style i czcionki', 'drobne', 'Wiele zewnętrznych usług na stronie',
                'Podstrona „'.$t['label'].'” łączy się z '.$t['third_party'].' zewnętrznymi serwerami: '.implode(', ', array_slice($t['third_party_list'], 0, 10)).'. Każda usługa (czat, mapy, piksele, widżety opinii) dokłada czas ładowania.',
                'Usunąć nieużywane usługi, a pozostałe ładować z opóźnieniem (po interakcji lub po kilku sekundach).');
        }
        $f = $worst('font_faces');
        $families = array_values(array_unique(array_merge(...array_map(fn ($s) => $s['fonts'], $stats))));
        if (count($families) >= 3 || $f['font_faces'] >= 12) {
            $this->add('Skrypty, style i czcionki', 'drobne', 'Dużo krojów i wariantów czcionek',
                ($families ? 'Strona pobiera z Google Fonts kroje: '.implode(', ', $families).'. ' : '').($f['font_faces'] >= 12 ? 'W kodzie jest '.$f['font_faces'].' deklaracji czcionek. ' : '').'Każdy krój i grubość to osobny plik do pobrania przed pokazaniem tekstu.',
                'Ograniczyć się do 1–2 krojów i potrzebnych grubości; czcionki wgrać lokalnie z font-display: swap (Elementor: Ustawienia → Wydajność → czcionki lokalnie).');
        }
        $eager = array_merge(...array_map(fn ($s) => $s['iframes_eager'], $stats));
        if ($eager) {
            $this->add('Skrypty, style i czcionki', 'drobne', 'Mapa lub film ładuje się od razu',
                'Na stronie '.implode(', ', array_unique($eager)).' ładuje się razem ze stroną, nawet jeśli klient do niej nie przewinie – to kilkaset KB i kilka zewnętrznych skryptów.',
                'Dodać loading="lazy" do ramki albo pokazać obrazek-zaślepkę, który wczytuje mapę/film po kliknięciu.');
        }
        $extras = array_values(array_unique(array_merge(...array_map(fn ($s) => $s['wp_extras'], $stats))));
        if (count($extras) >= 2) {
            $this->add('Skrypty, style i czcionki', 'drobne', 'Zbędne skrypty WordPressa',
                'Strona ładuje dodatki, które zwykle nie są potrzebne odwiedzającym: '.implode(', ', $extras).'.',
                'Wyłączyć je (np. Perfmatters, Asset CleanUp albo kilka linii w functions.php).');
        }
        $plug = $worst('plugins');
        if ($plug['plugins'] >= 20) {
            $this->add('Skrypty, style i czcionki', 'drobne', 'Pliki z bardzo wielu wtyczek',
                'Na podstronie „'.$plug['label'].'” ładują się pliki z '.$plug['plugins'].' wtyczek. Im więcej wtyczek, tym wolniej i tym więcej rzeczy do aktualizowania.',
                'Przejrzeć wtyczki i usunąć te, których funkcje się dublują lub nie są używane.');
        }
    }

    private function serverFindings(array $pages, array $stats): void
    {
        $slow = array_filter($stats, fn ($s) => ($s['ttfb_ms'] ?? 0) > 1200);
        $noCache = ! array_filter($stats, fn ($s) => $s['page_cache']);
        if ($slow) {
            $list = implode(', ', array_map(fn ($s) => $s['label'].' – '.number_format($s['ttfb_ms'] / 1000, 1, ',', '').' s', $slow));
            $this->add('Serwer i pamięć podręczna', max(array_column($slow, 'ttfb_ms')) > 2500 ? 'pilne' : 'srednie', 'Serwer długo odpowiada',
                'Czas do pierwszego bajtu odpowiedzi (TTFB): '.$list.'. Dobry wynik to poniżej 0,6 s – przez ten czas klient widzi pusty ekran.'.($noCache ? ' Nie widać śladów cache strony.' : ''),
                'Włączyć cache całych stron (np. LiteSpeed Cache, WP Rocket, cache hostingu), sprawdzić wersję PHP i obciążenie hostingu.');
        } elseif ($noCache && array_filter($stats, fn ($s) => ($s['ttfb_ms'] ?? 0) > 600)) {
            $this->add('Serwer i pamięć podręczna', 'drobne', 'Brak śladów cache strony',
                'Strony są generowane przy każdym wejściu (czas odpowiedzi powyżej 0,6 s, brak nagłówków ani znaczników wtyczki cache).',
                'Włączyć cache stron (wtyczka cache lub funkcja hostingu).', null, [], true);
        }
        $uncompressed = array_filter($stats, fn ($s) => ! $s['compressed'] && $s['html_kb'] > 20);
        if ($uncompressed && array_filter($pages, fn ($p) => ! empty($p['headers']))) {
            $this->add('Serwer i pamięć podręczna', 'srednie', 'Strona wysyłana bez kompresji',
                'Serwer nie kompresuje kodu strony (brak gzip/Brotli) – HTML, CSS i JS są przesyłane w pełnym rozmiarze, zwykle 3–5 razy większym.',
                'Włączyć kompresję gzip lub Brotli w panelu hostingu, .htaccess albo wtyczce cache.', null, [], true);
        }
        $big = array_filter($stats, fn ($s) => $s['html_kb'] >= 250);
        if ($big) {
            $b = reset($big);
            $this->add('Skrypty, style i czcionki', $b['html_kb'] >= 600 ? 'srednie' : 'drobne', 'Bardzo duży kod HTML',
                'Kod podstrony „'.$b['label'].'” waży '.$b['html_kb'].' KB'.($b['inline_css_kb'] >= 50 ? ', w tym '.$b['inline_css_kb'].' KB stylów wpisanych w kod' : '').'. Telefon musi go pobrać i przetworzyć, zanim zacznie pobierać obrazki i skrypty.',
                'Przenieść style do plików (Elementor: Ustawienia → Wydajność → „Wczytywanie CSS: plik zewnętrzny”), uprościć układ, usunąć ukryte sekcje.');
        }
        $dom = array_filter($stats, fn ($s) => $s['dom'] >= 1800);
        if ($dom) {
            $d = array_reduce($dom, fn ($c, $s) => ! $c || $s['dom'] > $c['dom'] ? $s : $c);
            $this->add('Skrypty, style i czcionki', $d['dom'] >= 3500 ? 'srednie' : 'drobne', 'Rozbudowana struktura strony',
                'Podstrona „'.$d['label'].'” ma '.number_format($d['dom'], 0, ',', ' ').' elementów w kodzie (zalecane: do ok. 1500). To typowe dla kreatorów stron – zagnieżdżone sekcje i kolumny spowalniają telefon przy wyświetlaniu i przewijaniu.',
                'Upraszczać sekcje (mniej zagnieżdżonych kontenerów), usuwać elementy ukryte na telefonie/komputerze zamiast je dublować.');
        }
    }

    // ------------------------------------------------------------------ pomocnicze

    private function cacheSignature(string $raw, array $headers): ?string
    {
        foreach (['x-litespeed-cache' => 'LiteSpeed Cache', 'cf-cache-status' => 'Cloudflare', 'x-cache' => 'cache serwera', 'x-proxy-cache' => 'cache serwera',
            'x-wp-super-cache' => 'WP Super Cache', 'x-rocket-nginx-serving-static' => 'WP Rocket', 'x-nginx-cache' => 'cache nginx', 'x-sucuri-cache' => 'Sucuri'] as $h => $name) {
            if (! empty($headers[$h])) {
                return $name;
            }
        }
        foreach (['WP Rocket' => 'WP Rocket', 'Cached page generated by WP-Super-Cache' => 'WP Super Cache', 'W3 Total Cache' => 'W3 Total Cache',
            'Page cached by LiteSpeed' => 'LiteSpeed Cache', 'Performance optimized by' => 'wtyczka cache', 'swift-performance' => 'Swift Performance', 'wpfc-' => 'WP Fastest Cache'] as $needle => $name) {
            if (str_contains($raw, $needle)) {
                return $name;
            }
        }

        return null;
    }

    private function add(string $group, string $sev, string $title, string $text, ?string $fix = null, ?string $evidence = null, array $urls = [], bool $verify = false): void
    {
        $this->findings[] = ['page' => 'site', 'section' => 'perf', 'group' => $group, 'sev' => $sev, 'title' => $title, 'text' => $text, 'fix' => $fix,
            'evidence' => $evidence, 'urls' => $urls, 'verify' => $verify, 'source' => 'auto'];
    }

    private function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }

    private function shortName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/plugins/([^/]+)/#', $path, $m)) {
            return $m[1].'/'.basename($path);
        }

        return parse_url($url, PHP_URL_HOST) && ! str_contains($url, '/wp-') ? (string) parse_url($url, PHP_URL_HOST).'/…/'.basename($path) : basename($path);
    }

    private function bare(string $host): string
    {
        return preg_replace('/^www\./i', '', strtolower($host));
    }

    private function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/^(#|data:|javascript:|about:|blob:)/i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https').':'.$href;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (empty($p['host'])) {
            return null;
        }
        $origin = ($p['scheme'] ?? 'https').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return $origin.preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/').$href;
    }
}
