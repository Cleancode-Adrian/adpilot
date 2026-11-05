<?php

namespace App\Services\SiteAudit;

use App\Models\Client;
use App\Models\SiteAudit;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Audyt strony (SEO i techniczny): przechodzi po podstronach jak robot wyszukiwarki
 * i zbiera problemy: błędy 4xx/5xx, niedziałające linki, tytuły/opisy, H1, alt, noindex, canonical,
 * szybkość, HTTPS i mieszana treść, robots.txt, mapa strony, dane strukturalne, Open Graph.
 */
class SiteAuditor
{
    private const UA = 'Mozilla/5.0 (compatible; CC-SiteAudit/1.0)';

    private const BATCH = 5;

    private const TIMEOUT = 15;

    private const SKIP_EXT = '/\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|pdf|docx?|xlsx?|pptx?|zip|rar|7z|gz|mp3|mp4|mov|avi|webm|css|js|json|xml|txt|woff2?|ttf|eot)(\?|$)/i';

    private string $host;

    private array $pages = [];      // url => dane strony

    private array $linkedFrom = []; // url docelowy => [strony źródłowe]

    private array $external = [];   // url zewnętrzny => [strony źródłowe]

    public function start(Client $client): SiteAudit
    {
        return $client->siteAudits()->create(['url' => $client->website_url, 'status' => 'pending']);
    }

    public function run(SiteAudit $audit): void
    {
        $audit->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        try {
            $this->audit($audit);
        } catch (Throwable $e) {
            $audit->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function audit(SiteAudit $audit): void
    {
        $audit->update(['status' => 'done', 'finished_at' => now()] + $this->crawl($audit->url));
    }

    /**
     * Sam audyt (bez zapisu) – używany też w audytach dowolnych stron (potencjalni klienci).
     *
     * @return array{score:int, pages_crawled:int, issues:array, summary:array, pages:array}
     */
    public function crawl(string $url, ?int $maxPages = null): array
    {
        $this->pages = $this->linkedFrom = $this->external = [];
        $root = rtrim($url, '/').'/';
        $this->host = $this->bareHost((string) parse_url($root, PHP_URL_HOST));
        $max = $maxPages ?? (int) config('adpilot.site_audit.max_pages', 60);

        // robots.txt i mapa strony
        $robots = $this->fetchText($root.'robots.txt');
        $robotsBlocksAll = $robots !== null && $this->robotsBlocksAll($robots);
        $sitemapUrls = $this->sitemapUrls($root, $robots);

        // Widoczność w AI: llms.txt i dostęp robotów AI w robots.txt
        $llms = $this->fetchText($root.'llms.txt');
        $llmsCheck = $llms !== null ? $this->validateLlmsTxt($llms, $root) : null;
        $aiBlocked = $robots !== null && ! $robotsBlocksAll ? $this->blockedAiBots($robots) : [];

        // HTTPS: czy wersja http:// przekierowuje na https://
        $httpRedirects = null;
        if (str_starts_with($root, 'https://')) {
            try {
                $r = Http::withUserAgent(self::UA)->timeout(self::TIMEOUT)->withOptions(['allow_redirects' => false])->get('http://'.parse_url($root, PHP_URL_HOST).'/');
                $httpRedirects = $r->status() >= 300 && $r->status() < 400 && str_starts_with((string) $r->header('Location'), 'https://');
            } catch (Throwable) {
                $httpRedirects = null; // port 80 zamknięty – nie oceniamy
            }
        }

        // Przechodzenie po stronie: strona główna + linki + adresy z mapy strony
        $queue = array_values(array_unique(array_merge([$root], array_slice($sitemapUrls, 0, $max))));
        $seen = array_fill_keys($queue, true);
        while ($queue && count($this->pages) < $max) {
            $batch = array_splice($queue, 0, min(self::BATCH, $max - count($this->pages)));
            foreach ($this->fetchPages($batch) as $url => $page) {
                $this->pages[$url] = $page;
                foreach ($page['links_internal'] ?? [] as $link) {
                    $this->linkedFrom[$link][] = $url;
                    if (! isset($seen[$link])) {
                        $seen[$link] = true;
                        $queue[] = $link;
                    }
                }
                foreach ($page['links_external'] ?? [] as $link) {
                    $this->external[$link][] = $url;
                }
            }
        }
        if (! $this->pages || ($this->pages[$root]['status'] ?? 0) === 0) {
            throw new \RuntimeException('Strona nie odpowiada: '.($this->pages[$root]['error'] ?? $root));
        }

        $externalBroken = $this->checkExternal(array_slice(array_keys($this->external), 0, (int) config('adpilot.site_audit.max_external', 40)));
        $issues = $this->issues($root, $robots, $robotsBlocksAll, $sitemapUrls, $httpRedirects, $externalBroken);
        $issues = $this->sortIssues($issues + $this->aiIssues($llms !== null, $llmsCheck, $aiBlocked));

        $html = array_filter($this->pages, fn ($p) => $p['html']);
        $times = array_filter(array_column($html, 'ms'));
        $penalty = 0;
        foreach ($issues as $i) {
            $share = $i['scope'] === 'site' ? 1 : min(1, max(0.3, $i['count'] / max(1, count($html)) * 2));
            $penalty += ['critical' => 15, 'high' => 8, 'medium' => 4, 'low' => 1.5][$i['severity']] * $share;
        }

        return [
            'pages_crawled' => count($this->pages),
            'score' => max(0, (int) round(100 - $penalty)),
            'issues' => array_values($issues),
            'summary' => [
                'pages' => count($this->pages),
                'html_pages' => count($html),
                'avg_ms' => $times ? (int) round(array_sum($times) / count($times)) : null,
                'https' => str_starts_with($root, 'https://'),
                'http_redirects' => $httpRedirects,
                'robots' => $robots !== null,
                'sitemap_urls' => count($sitemapUrls),
                'llms_txt' => $llms !== null,
                'llms_txt_links' => $llmsCheck['links'] ?? 0,
                'ai_bots_blocked' => array_column($aiBlocked, 'bot'),
                'external_checked' => min(count($this->external), (int) config('adpilot.site_audit.max_external', 40)),
                'counts' => collect($issues)->countBy('severity')->all(),
                'limit_reached' => count($this->pages) >= $max,
            ],
            'pages' => array_values(array_map(fn ($p) => array_intersect_key($p, array_flip(
                ['url', 'status', 'ms', 'bytes', 'title', 'description', 'h1', 'noindex', 'words', 'redirects', 'error']
            )), $this->pages)),
        ];
    }

    // ------------------------------------------------------------------ pobieranie

    /** @return array<string, array> */
    private function fetchPages(array $urls): array
    {
        $started = [];
        $responses = Http::pool(function (Pool $pool) use ($urls, &$started) {
            foreach ($urls as $url) {
                $started[$url] = microtime(true);
                $pool->as($url)->withUserAgent(self::UA)->timeout(self::TIMEOUT)->connectTimeout(10)
                    ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])->get($url);
            }
        });

        $out = [];
        foreach ($urls as $url) {
            $res = $responses[$url] ?? null;
            $ms = (int) round((microtime(true) - $started[$url]) * 1000);
            if (! $res instanceof Response) {
                $out[$url] = ['url' => $url, 'status' => 0, 'ms' => null, 'html' => false, 'error' => $res instanceof Throwable ? mb_substr($res->getMessage(), 0, 200) : 'Brak odpowiedzi'];

                continue;
            }
            $history = array_values(array_filter((array) ($res->headers()['X-Guzzle-Redirect-History'] ?? [])));
            $isHtml = str_contains(strtolower((string) $res->header('Content-Type')), 'text/html');
            $page = [
                'url' => $url,
                'status' => $res->status(),
                'ms' => $ms,
                'bytes' => strlen($res->body()),
                'redirects' => count($history),
                'final_url' => $history ? end($history) : $url,
                'html' => $isHtml && $res->successful(),
                'x_robots' => strtolower((string) $res->header('X-Robots-Tag')),
            ];
            if ($page['html']) {
                $page += $this->parse($res->body(), $page['final_url']);
                if (str_contains($page['x_robots'], 'noindex')) {
                    $page['noindex'] = true;
                }
            }
            $out[$url] = $page;
        }

        return $out;
    }

    private function parse(string $html, string $url): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $meta = fn (string $name) => trim((string) ($x->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$name']/@content")->item(0)?->nodeValue ?? ''));

        $title = trim(preg_replace('/\s+/u', ' ', (string) ($x->query('//title')->item(0)?->textContent ?? '')));
        $h1 = [];
        foreach ($x->query('//h1') as $n) {
            $h1[] = trim(preg_replace('/\s+/u', ' ', $n->textContent));
        }
        $imgs = $x->query('//img');
        $noAlt = 0;
        foreach ($imgs as $img) {
            if (! $img->hasAttribute('alt')) {
                $noAlt++;
            }
        }

        $internal = $externalLinks = [];
        foreach ($x->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:|sms:|whatsapp:)/i', $href)) {
                continue;
            }
            $abs = $this->absolute($href, $url);
            if (! $abs) {
                continue;
            }
            if ($this->bareHost((string) parse_url($abs, PHP_URL_HOST)) === $this->host) {
                if (! preg_match(self::SKIP_EXT, (string) parse_url($abs, PHP_URL_PATH)) && ! str_contains($abs, '/wp-admin') && ! str_contains($abs, '/wp-login')) {
                    $internal[$abs] = true;
                }
            } else {
                $externalLinks[$abs] = true;
            }
        }

        $mixed = 0;
        if (str_starts_with($url, 'https://')) {
            foreach ($x->query('//img/@src | //script/@src | //iframe/@src | //link[@rel="stylesheet"]/@href | //source/@src | //video/@src | //audio/@src') as $attr) {
                if (stripos($attr->nodeValue, 'http://') === 0) {
                    $mixed++;
                }
            }
        }

        $body = $x->query('//body')->item(0);
        foreach ($x->query('//script | //style | //noscript') as $n) {
            $n->parentNode?->removeChild($n);
        }
        $text = $body ? preg_replace('/\s+/u', ' ', $body->textContent) : '';

        return [
            'title' => mb_substr($title, 0, 300),
            'description' => mb_substr($meta('description'), 0, 400),
            'noindex' => str_contains(strtolower($meta('robots')), 'noindex'),
            'canonical' => trim((string) ($x->query("//link[@rel='canonical']/@href")->item(0)?->nodeValue ?? '')),
            'h1' => array_slice($h1, 0, 5),
            'img_total' => $imgs->length,
            'img_no_alt' => $noAlt,
            'words' => $text ? count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)) : 0,
            'lang' => (bool) $x->query('//html[@lang]')->length,
            'viewport' => $meta('viewport') !== '',
            'og' => (bool) $x->query("//meta[@property='og:title' or @property='og:image']")->length,
            'jsonld' => (bool) $x->query("//script[@type='application/ld+json']")->length,
            'mixed' => $mixed,
            'links_internal' => array_keys($internal),
            'links_external' => array_slice(array_keys($externalLinks), 0, 100),
        ];
    }

    private function checkExternal(array $urls): array
    {
        if (! $urls) {
            return [];
        }
        $broken = [];
        foreach (array_chunk($urls, 10) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk) {
                foreach ($chunk as $u) {
                    $pool->as($u)->withUserAgent(self::UA)->timeout(10)->connectTimeout(6)->withOptions(['allow_redirects' => ['max' => 5]])->get($u);
                }
            });
            foreach ($chunk as $u) {
                $r = $responses[$u] ?? null;
                if ($r instanceof Response) {
                    // 401/403/429/999 – serwis blokuje roboty (LinkedIn, Facebook) – nie traktujemy jako błąd
                    if (in_array($r->status(), [404, 410], true) || $r->status() >= 500) {
                        $broken[$u] = $r->status();
                    }
                } elseif ($r instanceof Throwable && preg_match('/resolve host|SSL|certificate|refused/i', $r->getMessage())) {
                    $broken[$u] = 0;
                }
            }
        }

        return $broken;
    }

    private function fetchText(string $url): ?string
    {
        try {
            $r = Http::withUserAgent(self::UA)->timeout(self::TIMEOUT)->get($url);

            return $r->successful() && ! str_contains(strtolower((string) $r->header('Content-Type')), 'text/html') ? $r->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Adresy z mapy strony (sitemap.xml, indeks map – jeden poziom). */
    private function sitemapUrls(string $root, ?string $robots): array
    {
        $candidates = [];
        if ($robots && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots, $m)) {
            $candidates = $m[1];
        }
        $candidates = array_unique(array_merge($candidates, [$root.'sitemap.xml', $root.'sitemap_index.xml', $root.'wp-sitemap.xml']));
        foreach ($candidates as $url) {
            $xml = $this->fetchText($url);
            if (! $xml || ! str_contains($xml, '<loc')) {
                continue;
            }
            preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $xml, $locs);
            $locs = array_map(fn ($l) => html_entity_decode(trim($l)), $locs[1]);
            if (str_contains($xml, '<sitemapindex')) {
                $urls = [];
                foreach (array_slice($locs, 0, 5) as $child) {
                    $childXml = $this->fetchText($child);
                    if ($childXml && preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $childXml, $m2)) {
                        $urls = array_merge($urls, array_map(fn ($l) => html_entity_decode(trim($l)), $m2[1]));
                    }
                }
                $locs = $urls;
            }
            $locs = array_values(array_filter(array_map(fn ($l) => $this->absolute($l, $root), $locs), fn ($l) => $l && $this->bareHost((string) parse_url($l, PHP_URL_HOST)) === $this->host && ! preg_match(self::SKIP_EXT, (string) parse_url($l, PHP_URL_PATH))));

            return array_slice(array_values(array_unique($locs)), 0, 500);
        }

        return [];
    }

    private function robotsBlocksAll(string $robots): bool
    {
        $agent = null;
        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                $agent = trim($m[1]);
            } elseif ($agent === '*' && preg_match('/^disallow:\s*\/\s*$/i', $line)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ problemy

    private function issues(string $root, ?string $robots, bool $robotsBlocksAll, array $sitemapUrls, ?bool $httpRedirects, array $externalBroken): array
    {
        $html = array_filter($this->pages, fn ($p) => $p['html']);
        $issues = [];
        $add = function (string $code, string $severity, string $title, string $description, string $action, array $urls = [], string $scope = 'pages', ?array $details = null) use (&$issues) {
            if ($scope === 'pages' && ! $urls) {
                return;
            }
            $issues[$code] = ['code' => $code, 'severity' => $severity, 'title' => $title, 'description' => $description, 'action' => $action,
                'count' => $scope === 'site' ? 1 : count($urls), 'urls' => array_slice(array_values($urls), 0, 30), 'details' => $details, 'scope' => $scope];
        };
        $where = fn (callable $fn) => array_keys(array_filter($html, $fn));

        // --- krytyczne
        if ($robotsBlocksAll) {
            $add('robots_block_all', 'critical', 'robots.txt blokuje całą stronę przed Google', 'W robots.txt jest „Disallow: /” dla wszystkich robotów – strona nie pojawi się w wyszukiwarce.',
                'Usuń „Disallow: /” z robots.txt (w WordPressie: Ustawienia → Czytanie → odznacz „Proś wyszukiwarki o nieindeksowanie”).', [], 'site');
        }
        if (! empty($this->pages[$root]['noindex'])) {
            $add('home_noindex', 'critical', 'Strona główna ma znacznik noindex', 'Google nie zaindeksuje strony głównej.', 'Usuń meta robots „noindex” (lub nagłówek X-Robots-Tag) ze strony głównej.', [$root]);
        }
        $errors5xx = array_keys(array_filter($this->pages, fn ($p) => $p['status'] >= 500 || $p['status'] === 0));
        $add('server_errors', 'critical', 'Podstrony z błędem serwera lub bez odpowiedzi', 'Te adresy zwracają błąd 5xx albo nie odpowiadają.', 'Sprawdź logi błędów PHP i hostingu – najczęściej wtyczka, limit pamięci albo przekroczony czas.', $errors5xx);

        $broken = [];
        $details = [];
        foreach ($this->pages as $url => $p) {
            if (in_array($p['status'], [404, 410], true) && isset($this->linkedFrom[$url])) {
                $broken[] = $url;
                $details[$url] = array_slice(array_values(array_unique($this->linkedFrom[$url])), 0, 3);
            }
        }
        $add('broken_internal', 'critical', 'Niedziałające linki wewnętrzne (404)', 'Linki na stronie prowadzą do nieistniejących podstron – złe wrażenie i strata dla SEO.',
            'Popraw albo usuń linki; dla usuniętych podstron ustaw przekierowanie 301 na najbliższy odpowiednik.', $broken, details: $details);

        if (str_starts_with($root, 'http://')) {
            $add('no_https', 'critical', 'Strona bez HTTPS', 'Przeglądarki oznaczają stronę jako „niezabezpieczoną”; Google preferuje HTTPS.', 'Włącz certyfikat SSL (Let\'s Encrypt w panelu hostingu) i przekierowanie na https://.', [], 'site');
        } elseif ($httpRedirects === false) {
            $add('http_no_redirect', 'high', 'Wersja http:// nie przekierowuje na https://', 'Strona działa pod dwoma adresami – duplikacja i ryzyko otwarcia niezabezpieczonej wersji.', 'Ustaw przekierowanie 301 z http:// na https:// (hosting / .htaccess / wtyczka).', [], 'site');
        }

        // --- wysokie
        $add('missing_title', 'high', 'Brak tytułu strony (title)', 'Tytuł to najważniejszy tekst w wynikach Google.', 'Dodaj unikalny tytuł (50–60 znaków) z główną frazą i nazwą firmy.', $where(fn ($p) => $p['title'] === ''));
        $dupes = collect($html)->filter(fn ($p) => $p['title'] !== '')->groupBy('title')->filter(fn ($g) => $g->count() > 1);
        $add('duplicate_title', 'high', 'Powielone tytuły stron', 'Kilka podstron ma ten sam tytuł – Google nie wie, którą pokazać.', 'Nadaj każdej podstronie unikalny tytuł opisujący jej treść.',
            $dupes->flatten(1)->pluck('url')->all(), details: $dupes->map(fn ($g) => $g->pluck('url')->take(5)->all())->take(10)->all());
        $add('missing_h1', 'high', 'Brak nagłówka H1', 'H1 mówi Google i odwiedzającym, o czym jest strona.', 'Dodaj jeden nagłówek H1 z główną frazą strony.', $where(fn ($p) => ! $p['h1']));
        $add('slow_pages', 'high', 'Wolno ładujące się podstrony (ponad 3 s)', 'Czas odpowiedzi serwera powyżej 3 sekund – odwiedzający uciekają, a reklamy drożeją.', 'Włącz cache strony, zoptymalizuj obrazy, sprawdź wtyczki i plan hostingu.', $where(fn ($p) => ($p['ms'] ?? 0) > 3000));
        if (! $sitemapUrls) {
            $add('no_sitemap', 'high', 'Brak mapy strony (sitemap.xml)', 'Mapa strony pomaga Google znaleźć wszystkie podstrony.', 'Włącz mapę strony (WordPress: wbudowana /wp-sitemap.xml albo Yoast/Rank Math) i dodaj ją w Google Search Console.', [], 'site');
        }
        $sitemapSet = array_flip($sitemapUrls);
        $add('sitemap_noindex', 'high', 'Strony z mapy strony mają noindex', 'Mapa strony mówi „indeksuj”, a strona „nie indeksuj” – sprzeczny sygnał.', 'Usuń noindex albo usuń te adresy z mapy strony.', $where(fn ($p) => ! empty($p['noindex']) && isset($sitemapSet[$p['url']])));
        $add('mixed_content', 'high', 'Mieszana treść (zasoby przez http://)', 'Strona HTTPS wczytuje obrazy/skrypty przez http:// – przeglądarka je blokuje albo pokazuje ostrzeżenie.', 'Zmień adresy zasobów na https:// (WordPress: Better Search Replace na http://twojadomena → https://twojadomena).', $where(fn ($p) => ($p['mixed'] ?? 0) > 0));

        // --- średnie
        $add('missing_description', 'medium', 'Brak opisu meta (description)', 'Bez opisu Google sam wybiera fragment tekstu do wyników – zwykle gorzej.', 'Dodaj opis 140–160 znaków zachęcający do kliknięcia.', $where(fn ($p) => $p['description'] === ''));
        $dDupes = collect($html)->filter(fn ($p) => $p['description'] !== '')->groupBy('description')->filter(fn ($g) => $g->count() > 1);
        $add('duplicate_description', 'medium', 'Powielone opisy meta', 'Te same opisy na wielu podstronach.', 'Napisz unikalny opis dla każdej ważnej podstrony.', $dDupes->flatten(1)->pluck('url')->all());
        $add('title_length', 'medium', 'Tytuł za długi lub za krótki', 'Tytuły poniżej 15 lub powyżej 65 znaków – za krótkie nic nie mówią, za długie są ucinane w Google.', 'Skróć lub rozbuduj tytuł do 50–60 znaków.',
            $where(fn ($p) => $p['title'] !== '' && (mb_strlen($p['title']) < 15 || mb_strlen($p['title']) > 65)));
        $add('multiple_h1', 'medium', 'Kilka nagłówków H1 na stronie', 'Więcej niż jeden H1 rozmywa temat strony.', 'Zostaw jeden H1, pozostałe zmień na H2.', $where(fn ($p) => count($p['h1']) > 1));
        $add('img_no_alt', 'medium', 'Obrazy bez opisu alternatywnego (alt)', 'Brak alt = gorsza dostępność i brak ruchu z Grafiki Google.', 'Uzupełnij atrybut alt opisujący obraz (w WordPressie: Media → obraz → Tekst alternatywny).', $where(fn ($p) => ($p['img_no_alt'] ?? 0) > 0),
            details: collect($html)->filter(fn ($p) => ($p['img_no_alt'] ?? 0) > 0)->map(fn ($p) => $p['img_no_alt'].' z '.$p['img_total'])->take(30)->all());
        $add('missing_canonical', 'medium', 'Brak adresu kanonicznego (canonical)', 'Canonical chroni przed duplikacją (np. adresy z parametrami).', 'Dodaj link rel="canonical" (wtyczki SEO robią to automatycznie).', $where(fn ($p) => $p['canonical'] === ''));
        $toRedirect = [];
        foreach ($this->pages as $url => $p) {
            if (($p['redirects'] ?? 0) > 0 && isset($this->linkedFrom[$url]) && $url !== $root) {
                $toRedirect[] = $url;
            }
        }
        $add('links_to_redirects', 'medium', 'Linki wewnętrzne prowadzą do przekierowań', 'Każde przekierowanie to dodatkowe opóźnienie i utrata części „mocy” linku.', 'Zmień linki tak, by prowadziły od razu na adres docelowy.', $toRedirect);
        $add('broken_external', 'medium', 'Niedziałające linki zewnętrzne', 'Linki do innych stron, które już nie istnieją.', 'Usuń lub podmień linki na działające.', array_keys($externalBroken),
            details: collect($externalBroken)->map(fn ($code, $u) => ['status' => $code ?: 'brak połączenia', 'on' => array_slice(array_values(array_unique($this->external[$u] ?? [])), 0, 2)])->all());
        $add('no_viewport', 'medium', 'Brak ustawień dla telefonów (meta viewport)', 'Strona może źle wyglądać na telefonie.', 'Dodaj <meta name="viewport" content="width=device-width, initial-scale=1">.', $where(fn ($p) => ! $p['viewport']));

        // --- niskie
        $add('no_lang', 'low', 'Brak języka strony (lang)', 'Atrybut lang pomaga wyszukiwarkom i czytnikom ekranu.', 'Dodaj <html lang="pl">.', $where(fn ($p) => ! $p['lang']));
        $add('no_og', 'low', 'Brak Open Graph (podgląd w social media)', 'Bez og:title / og:image link udostępniony na Facebooku wygląda przypadkowo.', 'Ustaw Open Graph (wtyczka SEO → Social).', $where(fn ($p) => ! $p['og']));
        if (! collect($html)->contains('jsonld', true)) {
            $add('no_schema', 'low', 'Brak danych strukturalnych (schema.org)', 'Dane strukturalne (firma, adres, opinie, produkty) dają lepsze wyniki w Google i AI.', 'Dodaj schema Organization/LocalBusiness (wtyczka SEO albo JSON-LD w szablonie).', [], 'site');
        }
        $add('thin_content', 'low', 'Mało treści (poniżej 200 słów)', 'Bardzo krótkie strony słabo pozycjonują się w Google.', 'Rozbuduj treść o konkretne informacje, FAQ, korzyści.', $where(fn ($p) => $p['words'] < 200 && empty($p['noindex'])));
        if ($robots === null) {
            $add('no_robots', 'low', 'Brak pliku robots.txt', 'Robots.txt informuje roboty, co indeksować, i wskazuje mapę strony.', 'Dodaj robots.txt z linią Sitemap: https://twojadomena/sitemap.xml.', [], 'site');
        }

        return $this->sortIssues($issues);
    }

    private function sortIssues(array $issues): array
    {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        uasort($issues, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']] ?: $b['count'] <=> $a['count']);

        return $issues;
    }

    // ------------------------------------------------------------------ widoczność w AI (llms.txt, roboty AI)

    /** Roboty AI: wyszukiwarki/asystenci (ważne dla widoczności) i roboty zbierające dane do trenowania modeli. */
    public const AI_BOTS = [
        'OAI-SearchBot' => ['ChatGPT – wyszukiwarka', 'search'],
        'ChatGPT-User' => ['ChatGPT – otwieranie linków', 'search'],
        'PerplexityBot' => ['Perplexity', 'search'],
        'Claude-SearchBot' => ['Claude – wyszukiwarka', 'search'],
        'Claude-User' => ['Claude – otwieranie linków', 'search'],
        'GPTBot' => ['OpenAI – trenowanie modeli', 'training'],
        'ClaudeBot' => ['Anthropic – trenowanie modeli', 'training'],
        'Google-Extended' => ['Google Gemini – trenowanie', 'training'],
        'Applebot-Extended' => ['Apple Intelligence – trenowanie', 'training'],
        'CCBot' => ['Common Crawl (zbiór danych dla AI)', 'training'],
    ];

    /**
     * Które roboty AI mają w robots.txt zakaz wejścia na całą stronę.
     * Grupa robota = linie po „User-agent: Nazwa”; jeśli robot nie ma własnej grupy, obowiązuje grupa „*”.
     *
     * @return list<array{bot:string, label:string, kind:string}>
     */
    public function blockedAiBots(string $robots): array
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (! preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) {
                continue;
            }
            $field = strtolower($m[1]);
            $value = trim($m[2]);
            if ($field === 'user-agent') {
                if (! $lastWasAgent || $current === null) {
                    $groups[] = ['agents' => [], 'disallow_root' => false, 'allow_root' => false];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }
            if ($field === 'disallow' && in_array($value, ['/', '/*'], true)) {
                $groups[$current]['disallow_root'] = true;
            } elseif ($field === 'allow' && in_array($value, ['/', '/*'], true)) {
                $groups[$current]['allow_root'] = true;
            }
        }

        $blockedFor = function (string $agent) use ($groups): ?bool {
            $matching = array_filter($groups, fn ($g) => in_array($agent, $g['agents'], true));
            if (! $matching) {
                return null;
            }
            $disallow = (bool) array_filter($matching, fn ($g) => $g['disallow_root']);
            $allow = (bool) array_filter($matching, fn ($g) => $g['allow_root']);

            return $disallow && ! $allow;
        };

        $out = [];
        foreach (self::AI_BOTS as $bot => [$label, $kind]) {
            if ($blockedFor(strtolower($bot)) ?? $blockedFor('*') ?? false) {
                $out[] = ['bot' => $bot, 'label' => $label, 'kind' => $kind];
            }
        }

        return $out;
    }

    /**
     * Sprawdza llms.txt wg specyfikacji llmstxt.org: H1 z nazwą w pierwszej linii, opcjonalne podsumowanie „> …”,
     * sekcje H2 z listami linków „- [nazwa](adres): opis”. Do 15 linków sprawdza, czy działają.
     *
     * @return array{problems:list<string>, links:int, broken:array<string,int>}
     */
    public function validateLlmsTxt(string $text, string $root): array
    {
        $problems = [];
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\R/', $text);
        $content = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        if (trim($text) === '') {
            return ['problems' => ['Plik jest pusty.'], 'links' => 0, 'broken' => []];
        }
        if (preg_match('/<(html|body|head)\b/i', $text)) {
            $problems[] = 'Plik zawiera HTML zamiast Markdown – serwer prawdopodobnie zwraca stronę zamiast pliku.';
        }
        if (! preg_match('/^#\s+\S/', $content[0] ?? '')) {
            $problems[] = 'Pierwsza linia musi być nagłówkiem z nazwą strony, np. „# Nazwa firmy”.';
        }
        $h1 = count(array_filter($content, fn ($l) => preg_match('/^#\s+\S/', $l)));
        if ($h1 > 1) {
            $problems[] = "Plik ma {$h1} nagłówki H1 („# …”) – powinien być dokładnie jeden, sekcje oznacz „## …”.";
        }
        if (! array_filter($content, fn ($l) => str_starts_with($l, '>'))) {
            $problems[] = 'Brak krótkiego opisu pod nazwą (linia zaczynająca się od „> ”) – AI nie wie od razu, czym zajmuje się firma.';
        }
        if (! array_filter($content, fn ($l) => preg_match('/^##\s+\S/', $l))) {
            $problems[] = 'Brak sekcji „## …” (np. „## Oferta”, „## Kontakt”) z listami linków.';
        }

        preg_match_all('/^\s*[-*]\s*\[([^\]]+)\]\(([^)\s]+)\)/m', $text, $m);
        $links = array_values(array_unique(array_filter(array_map(fn ($u) => $this->absolute($u, $root), $m[2]))));
        if (! $links) {
            $problems[] = 'Brak linków w formacie „- [Nazwa strony](https://adres): opis”.';
        }
        if (mb_strlen($text) > 200_000) {
            $problems[] = 'Plik jest bardzo duży (ponad 200 tys. znaków) – llms.txt ma być krótkim spisem; pełną treść daj w llms-full.txt.';
        }

        $broken = [];
        $toCheck = array_slice($links, 0, 15);
        if ($toCheck) {
            $responses = Http::pool(function (Pool $pool) use ($toCheck) {
                foreach ($toCheck as $u) {
                    $pool->as($u)->withUserAgent(self::UA)->timeout(10)->connectTimeout(6)->withOptions(['allow_redirects' => ['max' => 5]])->get($u);
                }
            });
            foreach ($toCheck as $u) {
                $r = $responses[$u] ?? null;
                if ($r instanceof Response && (in_array($r->status(), [404, 410], true) || $r->status() >= 500)) {
                    $broken[$u] = $r->status();
                } elseif ($r instanceof Throwable && preg_match('/resolve host|SSL|certificate|refused/i', $r->getMessage())) {
                    $broken[$u] = 0;
                }
            }
        }

        return ['problems' => $problems, 'links' => count($links), 'broken' => $broken];
    }

    private function aiIssues(bool $hasLlms, ?array $llms, array $aiBlocked): array
    {
        $issues = [];
        $site = function (string $code, string $severity, string $title, string $description, string $action, ?array $details = null, array $urls = []) use (&$issues) {
            $issues[$code] = [
                'code' => $code, 'severity' => $severity, 'title' => $title, 'description' => $description, 'action' => $action,
                'count' => $urls ? count($urls) : 1, 'urls' => array_slice($urls, 0, 30), 'details' => $details, 'scope' => $urls ? 'pages' : 'site',
            ];
        };

        $search = array_values(array_filter($aiBlocked, fn ($b) => $b['kind'] === 'search'));
        $training = array_values(array_filter($aiBlocked, fn ($b) => $b['kind'] === 'training'));
        if ($search) {
            $site('ai_search_blocked', 'high', 'robots.txt blokuje wyszukiwarki AI',
                'Asystenci AI (ChatGPT, Perplexity, Claude) nie mogą wejść na stronę, więc nie polecą jej w odpowiedziach. Zablokowane: '.implode(', ', array_column($search, 'label')).'.',
                'Usuń „Disallow: /” dla tych robotów w robots.txt (albo dodaj dla nich „Allow: /”). Blokadę robotów trenujących modele można zostawić – to osobne roboty.',
                array_column($search, 'label', 'bot'));
        }
        if ($training) {
            $site('ai_training_blocked', 'low', 'robots.txt blokuje roboty trenujące AI',
                'Te roboty zbierają treści do trenowania modeli: '.implode(', ', array_column($training, 'label')).'. Blokada to często świadoma decyzja, ale model może wtedy słabiej „znać” markę.',
                'Jeśli klient tego nie chciał – usuń blokadę w robots.txt (wtyczki bezpieczeństwa i CDN, np. Cloudflare, potrafią ją dodać same). Jeśli chciał – zostaw.',
                array_column($training, 'label', 'bot'));
        }

        if (! $hasLlms) {
            $site('no_llms_txt', 'low', 'Brak pliku llms.txt',
                'llms.txt to krótki spis najważniejszych podstron w Markdown dla asystentów AI (ChatGPT, Claude, Perplexity). To nowy, nieobowiązkowy standard – Google go nie wymaga, ale pomaga AI zrozumieć ofertę.',
                'Dodaj plik /llms.txt: „# Nazwa firmy”, „> jedno zdanie o firmie”, sekcje „## Oferta”, „## Kontakt” z linkami „- [Usługa](https://…): opis”. W WordPressie generator llms.txt mają m.in. Yoast SEO i Rank Math.');
        } elseif ($llms) {
            if ($llms['problems']) {
                $site('llms_txt_invalid', 'medium', 'Plik llms.txt ma błędy formatu',
                    'Plik istnieje, ale nie trzyma się formatu llms.txt – asystenci AI mogą go źle odczytać. Znalezione błędy: '
                        .implode(' ', array_map(fn ($p, $n) => ($n + 1).') '.$p, $llms['problems'], array_keys($llms['problems']))),
                    'Popraw plik /llms.txt według listy błędów (wzór formatu: llmstxt.org).', $llms['problems']);
            }
            if ($llms['broken']) {
                $site('llms_txt_broken_links', 'medium', 'Niedziałające linki w llms.txt',
                    'llms.txt wskazuje AI podstrony, które nie istnieją albo zwracają błąd.',
                    'Popraw lub usuń te linki w /llms.txt.', null, array_keys($llms['broken']));
            }
        }

        return $issues;
    }

    // ------------------------------------------------------------------ pomocnicze

    private function absolute(string $href, string $base): ?string
    {
        if (str_starts_with($href, '//')) {
            $href = parse_url($base, PHP_URL_SCHEME).':'.$href;
        }
        if (! preg_match('#^https?://#i', $href)) {
            $p = parse_url($base);
            if (! isset($p['host'])) {
                return null;
            }
            $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
            if (str_starts_with($href, '/')) {
                $href = $origin.$href;
            } elseif (str_starts_with($href, '?')) {
                $href = $origin.($p['path'] ?? '/').$href;
            } else {
                $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
                $href = $origin.$dir.$href;
            }
        }
        $href = preg_replace('/#.*$/', '', $href);
        // ../ i ./ w ścieżce
        $parts = parse_url($href);
        if (! $parts || empty($parts['host'])) {
            return null;
        }
        $segments = [];
        foreach (explode('/', $parts['path'] ?? '/') as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $path = implode('/', $segments) ?: '/';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function bareHost(string $host): string
    {
        return preg_replace('/^www\./i', '', strtolower($host));
    }
}
