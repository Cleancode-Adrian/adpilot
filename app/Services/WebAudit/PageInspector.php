<?php

namespace App\Services\WebAudit;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Szczegółowe sprawdzenie najważniejszych podstron (strona główna, kontakt, oferta/cennik, przykładowa usługa, o firmie):
 * - wybiera podstrony po linkach z menu i adresach,
 * - zbiera to, co widzi klient (nagłówki, tekst, linki, formularze, obrazy) – materiał do przeglądu treści przez AI,
 * - wykrywa błędy w kodzie, które widać tylko w źródle strony (linki www/bez www, zapis telefonów, piksel bez zgody,
 *   różne wersje plików na podstronach, puste elementy, liczniki z zerami, nazwy plików zamiast opisów obrazów…),
 * - wnioskuje o różnicach mobile/desktop z budowy kodu (ukryte warianty nagłówka, pozycje menu „#”, przyciski tylko „tel:”).
 *
 * Uwagi mają format: page (rola podstrony albo 'site'), section ('page' | 'code' | 'mobile'), group, sev (pilne|srednie|drobne),
 * title, text, fix, evidence (cytat z kodu/treści), urls, verify (true = do potwierdzenia w przeglądarce).
 */
class PageInspector
{
    private const UA = 'Mozilla/5.0 (compatible; CC-SiteAudit/1.0)';

    public const ROLES = [
        'home' => 'Strona główna',
        'offer' => 'Oferta i cennik',
        'detail' => 'Przykładowa strona usługi / oferty',
        'contact' => 'Kontakt',
        'about' => 'O firmie',
    ];

    private const ROLE_PATTERNS = [
        'contact' => '/kontakt|contact|zapisy|napisz-do-nas|umow-wizyte/i',
        'offer' => '/cennik|ceny|oferta|oferty|uslugi|usługi|pricing|price|services|produkty|sklep|shop|katalog|zabiegi/i',
        'about' => '/o-nas|o nas|o-firmie|o firmie|about|kim-jestesmy|kim jesteśmy|zespol|zespół/i',
    ];

    private const DETAIL_PATTERN = '#/(product|produkt|produkty|uslugi|usluga|oferta|oferty|services?|zabiegi?|kurs|kursy)/[^/?]+#i';

    private const GENERIC_LINK = '/^(czytaj|zobacz|sprawdź|sprawdz|więcej|wiecej|kliknij|szczegóły|szczegoly|read more|learn more|zapisz|kup|zamów|zamow|dodaj|umów|umow|przejdź|przejdz|poznaj|pobierz)\b/iu';

    private const HIDDEN_MOBILE = '/(?:^|\s)(elementor-hidden-mobile|elementor-hidden-phone|hidden-mobile|hide-mobile|hidden-xs|visible-desktop|desktop-only|show-for-large|d-none)(?:\s|$)/';

    private const HIDDEN_DESKTOP = '/(?:^|\s)(elementor-hidden-desktop|hidden-desktop|hide-desktop|mobile-only|d-md-none|d-lg-none|visible-xs|hide-for-large)(?:\s|$)/';

    private const DEFAULT_SITE_NAMES = '/^(m[oó]j blog|my blog|my wordpress blog|kolejna witryna|just another wordpress site|wordpress|site title|nazwa witryny|strona|test)$/iu';

    private string $siteHost = '';

    private bool $siteWww = false;

    private string $scheme = 'https';

    private array $findings = [];

    public function __construct(private PerformanceInspector $performance) {}

    // ------------------------------------------------------------------ wejście

    /**
     * @return array{pages: array<string, array>, findings: list<array>, facts: array}
     */
    public function inspect(string $url): array
    {
        $this->findings = [];
        $root = rtrim($url, '/').'/';

        $home = $this->fetch([$root])[$root] ?? null;
        if (! $home || ! $home['ok']) {
            throw new \RuntimeException('Nie udało się pobrać strony głównej: '.($home['error'] ?? $root));
        }
        $finalHost = strtolower((string) parse_url($home['final_url'], PHP_URL_HOST));
        $this->siteHost = $this->bare($finalHost);
        $this->siteWww = str_starts_with($finalHost, 'www.');
        $this->scheme = (string) parse_url($home['final_url'], PHP_URL_SCHEME) ?: 'https';

        $pages = ['home' => $this->snapshot('home', $home)];

        // Kontakt, oferta, o firmie – po tekście linku i adresie (najpierw linki z menu i nagłówka)
        $candidates = $this->roleCandidates($pages['home']['links']);
        $toFetch = array_filter($candidates);
        foreach ($this->fetch(array_values($toFetch)) as $u => $res) {
            $role = array_search($u, $toFetch, true);
            if ($role && $res) {
                $pages[$role] = $this->snapshot($role, $res);
            }
        }

        // Przykładowa karta oferty / usługi – z oferty (albo strony głównej)
        $detail = $this->detailCandidate($pages['offer'] ?? null, $pages['home'], array_values(array_map(fn ($p) => $p['final_url'], $pages)));
        if ($detail) {
            $res = $this->fetch([$detail])[$detail] ?? null;
            if ($res) {
                $pages['detail'] = $this->snapshot('detail', $res);
            }
        }

        // Kolejność jak w raporcie
        $pages = array_replace(array_intersect_key(array_flip(array_keys(self::ROLES)), $pages), $pages);

        foreach ($pages as $role => $p) {
            if (! $p['ok']) {
                $this->add($role, 'page', 'Dostępność', 'pilne', 'Podstrona „'.self::ROLES[$role].'” nie działa',
                    'Link z menu prowadzi pod adres, który zwraca błąd '.($p['status'] ?: 'połączenia').'. Klient, który tam kliknie, nie zobaczy treści.',
                    'Przywróć podstronę albo popraw link w menu.', $p['url'], [$p['url']]);
            }
        }
        $ok = array_filter($pages, fn ($p) => $p['ok']);

        $this->checkSite($ok);
        $this->checkLanguage($ok);
        foreach ($ok as $role => $p) {
            $this->checkPage($role, $p);
        }
        $this->checkCrossPage($ok);
        $this->checkMobile($ok);

        $performance = null;
        try {
            $performance = $this->performance->inspect($ok);
            array_push($this->findings, ...$performance['findings']);
            unset($performance['findings']);
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'pages' => array_map(fn ($p) => array_diff_key($p, array_flip(['raw', 'strings'])), $pages),
            'findings' => $this->findings,
            'performance' => $performance,
            'facts' => [
                'host' => $this->siteHost,
                'www' => $this->siteWww,
                'phones' => $this->phones($ok),
                'emails' => array_values(array_unique(array_merge(...array_values(array_map(fn ($p) => $p['emails'], $ok)) ?: [[]]))),
                'wordpress' => (bool) array_filter($ok, fn ($p) => str_contains($p['raw'], '/wp-content/')),
                'builder' => $this->builder($ok['home']['raw'] ?? ''),
            ],
        ];
    }

    // ------------------------------------------------------------------ pobieranie i wybór podstron

    /** @return array<string, array|null> */
    private function fetch(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (! $urls) {
            return [];
        }
        $responses = Http::pool(function (Pool $pool) use ($urls) {
            foreach ($urls as $u) {
                $pool->as($u)->withUserAgent(self::UA)->timeout(20)->connectTimeout(10)
                    ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])->get($u);
            }
        });
        $out = [];
        foreach ($urls as $u) {
            $r = $responses[$u] ?? null;
            if (! $r instanceof Response) {
                $out[$u] = ['url' => $u, 'final_url' => $u, 'status' => 0, 'ok' => false, 'body' => '', 'error' => $r instanceof Throwable ? mb_substr($r->getMessage(), 0, 200) : 'Brak odpowiedzi'];

                continue;
            }
            $history = array_values(array_filter((array) ($r->headers()['X-Guzzle-Redirect-History'] ?? [])));
            $isHtml = str_contains(strtolower((string) $r->header('Content-Type')), 'html');
            $headers = [];
            foreach ($r->headers() as $name => $values) {
                $headers[strtolower($name)] = mb_substr(implode(', ', (array) $values), 0, 200);
            }
            $stats = rescue(fn () => $r->handlerStats(), [], false);
            $out[$u] = ['url' => $u, 'final_url' => $history ? end($history) : $u, 'status' => $r->status(), 'ok' => $r->successful() && $isHtml,
                'body' => $isHtml ? $r->body() : '', 'error' => null,
                'headers' => array_intersect_key($headers, array_flip(['content-encoding', 'x-encoded-content-encoding', 'cache-control', 'server', 'x-cache', 'cf-cache-status', 'x-litespeed-cache',
                    'x-proxy-cache', 'x-wp-super-cache', 'x-rocket-nginx-serving-static', 'x-nginx-cache', 'x-sucuri-cache', 'age', 'content-type'])),
                'ttfb_ms' => isset($stats['starttransfer_time']) && $stats['starttransfer_time'] > 0 ? (int) round($stats['starttransfer_time'] * 1000) : null];
        }

        return $out;
    }

    /** @return array<string, string|null> rola => adres */
    private function roleCandidates(array $links): array
    {
        $out = ['contact' => null, 'offer' => null, 'about' => null];
        $internal = array_filter($links, fn ($l) => $l['internal'] && $l['abs'] && ! $this->sameUrl($l['abs'], $this->rootUrl()));
        // linki z menu/nagłówka mają pierwszeństwo przed treścią i stopką
        usort($internal, fn ($a, $b) => (['nav' => 0, 'header' => 0, 'main' => 1, 'footer' => 2][$a['zone']] ?? 3) <=> (['nav' => 0, 'header' => 0, 'main' => 1, 'footer' => 2][$b['zone']] ?? 3));
        foreach (array_keys($out) as $role) {
            foreach ($internal as $l) {
                $path = (string) parse_url($l['abs'], PHP_URL_PATH);
                if (preg_match(self::ROLE_PATTERNS[$role], $l['text']) || preg_match(self::ROLE_PATTERNS[$role], $path)) {
                    if (in_array($l['abs'], array_filter($out), true) || substr_count(trim($path, '/'), '/') > 1) {
                        continue;
                    }
                    $out[$role] = $l['abs'];

                    break;
                }
            }
        }

        return $out;
    }

    private function detailCandidate(?array $offer, array $home, array $taken): ?string
    {
        $offerPath = $offer ? rtrim((string) parse_url($offer['final_url'], PHP_URL_PATH), '/') : null;
        foreach (array_filter([$offer, $home]) as $p) {
            $links = array_filter($p['links'], fn ($l) => $l['internal'] && $l['abs'] && $l['zone'] === 'main');
            foreach ($links as $l) {
                $path = rtrim((string) parse_url($l['abs'], PHP_URL_PATH), '/');
                $isChild = $offerPath && $offerPath !== '' && str_starts_with($path, $offerPath.'/') && $path !== $offerPath;
                if (($isChild || preg_match(self::DETAIL_PATTERN, $path)) && ! array_filter($taken, fn ($t) => $this->sameUrl($t, $l['abs']))) {
                    return $l['abs'];
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ zrzut podstrony

    private function snapshot(string $role, array $res): array
    {
        $base = ['role' => $role, 'label' => self::ROLES[$role], 'url' => $res['url'], 'final_url' => $res['final_url'], 'status' => $res['status'], 'ok' => $res['ok']];
        if (! $res['ok']) {
            return $base + ['title' => null, 'links' => [], 'emails' => [], 'raw' => ''];
        }
        $html = $res['body'];
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $meta = fn (string $attr, string $name) => trim((string) ($x->query("//meta[translate(@$attr,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$name']/@content")->item(0)?->nodeValue ?? ''));

        $links = [];
        foreach ($x->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            $text = $this->clean($a->textContent) ?: $this->clean($a->getAttribute('aria-label')) ?: $this->clean($a->getAttribute('title'));
            $abs = preg_match('/^(#|mailto:|tel:|sms:|javascript:|whatsapp:|data:)/i', $href) || $href === '' ? null : $this->absolute($href, $res['final_url']);
            $host = $abs ? strtolower((string) parse_url($abs, PHP_URL_HOST)) : null;
            $links[] = [
                'text' => mb_substr($text, 0, 140),
                'href' => mb_substr($href, 0, 300),
                'abs' => $abs,
                'internal' => $host !== null && $this->bare($host) === $this->siteHost,
                'zone' => $this->zone($a),
                'hidden_mobile' => $this->hiddenOn($a, self::HIDDEN_MOBILE),
                'hidden_desktop' => $this->hiddenOn($a, self::HIDDEN_DESKTOP),
                'button' => (bool) preg_match('/button|btn/i', $a->getAttribute('class').' '.($a->parentNode instanceof DOMElement ? $a->parentNode->getAttribute('class') : '')),
                'has_submenu' => $a->parentNode instanceof DOMElement && $a->parentNode->nodeName === 'li' && $x->query('./ul|./div/ul', $a->parentNode)->length > 0,
                'submenu_size' => $a->parentNode instanceof DOMElement && $a->parentNode->nodeName === 'li' ? $x->query('./ul/li|./div/ul/li', $a->parentNode)->length : 0,
            ];
        }

        $images = [];
        foreach ($x->query('//img') as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src');
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            $images[] = ['src' => mb_substr($src, 0, 300), 'alt' => $img->hasAttribute('alt') ? $this->clean($img->getAttribute('alt')) : null, 'zone' => $this->zone($img)];
        }

        $forms = [];
        foreach ($x->query('//form') as $form) {
            $forms[] = $this->form($x, $form);
        }

        $headings = [];
        foreach ($x->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
            $t = $this->clean($h->textContent);
            $headings[] = ['level' => (int) substr($h->nodeName, 1), 'text' => mb_substr($t, 0, 200), 'empty' => $t === '',
                'zone' => $this->zone($h), 'in_bold' => $x->query('ancestor::b|ancestor::strong', $h)->length > 0];
        }

        // Teksty do sprawdzenia języka: widoczne (z miejscem na stronie) i ukryte w kodzie (atrybuty, teksty dla czytników ekranu)
        $strings = ['visible' => [], 'code' => []];
        foreach ($x->query('//body//text()[normalize-space()][not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::svg) and not(ancestor::template) and not(ancestor::code)]') as $tn) {
            $t = $this->clean($tn->textContent);
            if (mb_strlen($t) < 3 || mb_strlen($t) > 300) {
                continue;
            }
            $parent = $tn->parentNode;
            if ($parent instanceof DOMElement && $this->hiddenOn($parent, '/(?:^|\s)(screen-reader-text|sr-only|elementor-screen-only|visually-hidden|screen-reader)(?:\s|$)/')) {
                $strings['code'][$t] = 'tekst dla czytników ekranu';
            } else {
                $strings['visible'][$t] ??= ['header' => 'menu', 'nav' => 'menu', 'footer' => 'stopka', 'main' => 'treść'][$parent ? $this->zone($tn) : 'main'];
            }
        }
        foreach (['alt' => '//img/@alt', 'title' => '//*[not(self::link) and not(self::iframe)]/@title', 'aria-label' => '//@aria-label', 'placeholder' => '//@placeholder',
            'value' => '//input[@type="submit" or @type="button"]/@value', 'data-label' => '//@data-label'] as $attr => $q) {
            foreach ($x->query($q) as $n) {
                $t = $this->clean($n->nodeValue);
                if (mb_strlen($t) >= 3 && mb_strlen($t) <= 200) {
                    $strings['code'][$t] ??= $attr.'="…"';
                }
            }
        }
        foreach (['og:title', 'og:description'] as $prop) {
            if (($v = $meta('property', $prop)) !== '') {
                $strings['code'][$v] ??= $prop;
            }
        }
        if (($v = $meta('name', 'description')) !== '') {
            $strings['code'][$v] ??= 'meta description';
        }

        // Dostępność: linki i przyciski bez nazwy, ramki bez tytułu
        $unnamedLinks = [];
        foreach ($x->query('//a[@href]') as $a) {
            /** @var DOMElement $a */
            if ($this->clean($a->textContent) === '' && $a->getAttribute('aria-label') === '' && $a->getAttribute('title') === '' && $a->getAttribute('aria-labelledby') === ''
                && ! $x->query('.//img[normalize-space(@alt)] | .//*[@aria-label] | .//svg/title', $a)->length) {
                $unnamedLinks[] = mb_substr($a->getAttribute('href'), 0, 120);
            }
        }
        $unnamedButtons = 0;
        foreach ($x->query('//button') as $b) {
            /** @var DOMElement $b */
            if ($this->clean($b->textContent) === '' && $b->getAttribute('aria-label') === '' && $b->getAttribute('title') === '' && $b->getAttribute('aria-labelledby') === '') {
                $unnamedButtons++;
            }
        }
        $a11y = [
            'unnamed_links' => array_values(array_unique($unnamedLinks)),
            'unnamed_buttons' => $unnamedButtons,
            'iframes_no_title' => $x->query('//iframe[not(@title) or normalize-space(@title)=""]')->length,
        ];
        $faq = [];
        foreach ($x->query("//*[contains(@class,'accordion-title') or contains(@class,'toggle-title') or contains(@class,'faq-question') or contains(@class,'e-n-accordion-item-title-text')]") as $q) {
            $t = $this->clean($q->textContent);
            if ($t !== '' && mb_strlen($t) < 200) {
                $faq[] = $t;
            }
        }

        $emptyLi = 0;
        foreach ($x->query('//li[not(normalize-space()) and not(.//img) and not(.//svg) and not(.//i) and not(.//input) and not(.//span[@class])]') as $li) {
            if ($this->zone($li) === 'main') {
                $emptyLi++;
            }
        }
        $zeroCounters = [];
        foreach ($x->query("//*[contains(@class,'counter-number') or @data-to-value or @data-count or @data-countup or contains(@class,'elementor-counter-number')]") as $c) {
            /** @var DOMElement $c */
            $t = $this->clean($c->textContent);
            $target = $c->getAttribute('data-to-value') ?: $c->getAttribute('data-count') ?: $c->getAttribute('data-countup');
            if (preg_match('/^0([.,]0)?\s*[%+]?$/', $t) && $target !== '' && $target !== '0') {
                $zeroCounters[] = $target;
            } elseif ($t === '0' && $target === '') {
                $zeroCounters[] = '?';
            }
        }

        $headerBlocks = [];
        foreach ($x->query("//header[not(ancestor::header)] | //*[contains(concat(' ',normalize-space(@class),' '),' elementor-location-header ')][not(ancestor::header)]") as $hb) {
            /** @var DOMElement $hb */
            $headerBlocks[] = [
                'tel' => $x->query('.//a[starts-with(@href,"tel:")]', $hb)->length,
                'menu_items' => $x->query('.//nav//li | .//ul[contains(@class,"menu")]/li', $hb)->length,
                'hidden_mobile' => $this->hiddenOn($hb, self::HIDDEN_MOBILE) || (bool) $x->query('.//*[contains(@class,"elementor-hidden-mobile")]//a[starts-with(@href,"tel:")]', $hb)->length,
            ];
        }

        $text = $this->visibleText($dom, $role === 'home');

        return $base + [
            'title' => $this->clean((string) ($x->query('//title')->item(0)?->textContent ?? '')),
            'description' => $meta('name', 'description'),
            'og_site_name' => $meta('property', 'og:site_name'),
            'og_title' => $meta('property', 'og:title'),
            'viewport' => $meta('name', 'viewport'),
            'lang' => strtolower((string) ($x->query('//html/@lang')->item(0)?->nodeValue ?? '')),
            'lang_raw' => (string) ($x->query('//html/@lang')->item(0)?->nodeValue ?? ''),
            'site_verification' => array_map(fn ($n) => $n->nodeValue, iterator_to_array($x->query("//meta[@name='google-site-verification']/@content"))),
            'headings' => array_slice($headings, 0, 120),
            'strings' => ['visible' => array_slice($strings['visible'], 0, 1500, true), 'code' => array_slice($strings['code'], 0, 600, true)],
            'a11y' => $a11y,
            'headers' => $res['headers'] ?? [],
            'ttfb_ms' => $res['ttfb_ms'] ?? null,
            'faq' => array_slice($faq, 0, 30),
            'links' => array_slice($links, 0, 400),
            'images' => array_slice($images, 0, 120),
            'forms' => $forms,
            'emails' => array_values(array_unique(array_map(fn ($l) => strtolower(trim(explode('?', substr($l['href'], 7))[0])), array_filter($links, fn ($l) => stripos($l['href'], 'mailto:') === 0)))),
            'empty_li' => $emptyLi,
            'zero_counters' => $zeroCounters,
            'header_blocks' => $headerBlocks,
            'footer_links' => array_values(array_unique(array_filter(array_map(fn ($l) => $l['zone'] === 'footer' ? $l['text'] : null, $links)))),
            'text' => $text,
            'raw' => $html,
        ];
    }

    private function form(DOMXPath $x, DOMElement $form): array
    {
        $fields = [];
        foreach ($x->query('.//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="image")] | .//select | .//textarea', $form) as $f) {
            /** @var DOMElement $f */
            $id = $f->getAttribute('id');
            $label = null;
            $labelVisible = false;
            if ($id !== '' && ($l = $x->query('//label[@for="'.addslashes($id).'"]')->item(0))) {
                $label = $this->clean($l->textContent);
                $labelVisible = ! preg_match('/screen-only|sr-only|screen-reader|visually-hidden/', $l->getAttribute('class'));
            } elseif ($l = $x->query('ancestor::label', $f)->item(0)) {
                $label = $this->clean($l->textContent);
                $labelVisible = true;
            }
            $consentLinks = 0;
            if ($f->getAttribute('type') === 'checkbox') {
                $wrap = $f->parentNode?->parentNode;
                $label ??= $wrap ? mb_substr($this->clean($wrap->textContent), 0, 400) : null;
                $consentLinks = $wrap ? $x->query('.//a', $wrap)->length : 0;
                $labelVisible = true;
            }
            $options = [];
            if ($f->nodeName === 'select') {
                foreach ($x->query('.//option', $f) as $o) {
                    $options[] = $this->clean($o->textContent);
                }
            }
            $fields[] = [
                'tag' => $f->nodeName,
                'type' => strtolower($f->getAttribute('type') ?: ($f->nodeName === 'input' ? 'text' : $f->nodeName)),
                'name' => mb_substr($f->getAttribute('name'), 0, 80),
                'label' => $label ? mb_substr($label, 0, 400) : null,
                'label_visible' => $labelVisible,
                'aria' => $f->getAttribute('aria-label') !== '',
                'placeholder' => $this->clean($f->getAttribute('placeholder')),
                'required' => $f->hasAttribute('required') || $f->getAttribute('aria-required') === 'true',
                'options' => array_slice($options, 0, 40),
                'consent_links' => $consentLinks,
            ];
        }
        $submit = $x->query('.//button | .//input[@type="submit"]', $form)->item(0);

        return ['fields' => $fields, 'submit' => $submit ? $this->clean($submit->textContent ?: $submit->getAttribute('value')) : null];
    }

    /** Tekst widoczny dla klienta w kolejności z kodu – materiał do przeglądu treści. */
    private function visibleText(DOMDocument $dom, bool $withChrome): string
    {
        $x = new DOMXPath($dom);
        foreach ($x->query('//script | //style | //noscript | //svg | //iframe | //template') as $n) {
            $n->parentNode?->removeChild($n);
        }
        $lines = [];
        $seen = [];
        $nodes = $x->query('//h1|//h2|//h3|//h4|//h5|//h6|//p|//li[not(.//li) and not(.//p)]|//button|//label|//td|//th|//blockquote|//figcaption|//option'
            .'|//a[not(ancestor::p) and not(ancestor::li) and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3) and not(ancestor::h4)]|//div[text()[normalize-space()]]|//span[contains(@class,"heading-title") or contains(@class,"counter")]');
        foreach ($nodes as $n) {
            /** @var DOMElement $n */
            $zone = $this->zone($n);
            if (! $withChrome && in_array($zone, ['header', 'nav', 'footer'], true)) {
                continue;
            }
            $t = $n->nodeName === 'div'
                ? $this->clean(implode(' ', array_map(fn ($c) => $c->nodeType === XML_TEXT_NODE ? $c->textContent : '', iterator_to_array($n->childNodes))))
                : $this->clean($n->textContent);
            if ($t === '' || mb_strlen($t) < 2 || isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;
            $prefix = match (true) {
                (bool) preg_match('/^h([1-6])$/', $n->nodeName, $m) => str_repeat('#', (int) $m[1]).' ',
                $n->nodeName === 'li' => '- ',
                $n->nodeName === 'a' || $n->nodeName === 'button' => '[przycisk/link] ',
                $n->nodeName === 'option' => '[opcja] ',
                default => '',
            };
            $lines[] = ($zone === 'footer' ? '[stopka] ' : ($zone === 'header' || $zone === 'nav' ? '[menu] ' : '')).$prefix.mb_substr($t, 0, 600);
        }
        $text = implode("\n", $lines);

        return mb_strlen($text) > 14000 ? mb_substr($text, 0, 14000)."\n[… dalsza treść ucięta]" : $text;
    }

    // ------------------------------------------------------------------ sprawdzenia: cała strona

    private function checkSite(array $pages): void
    {
        $home = $pages['home'];

        // Nazwa witryny przy udostępnianiu
        $names = array_unique(array_filter(array_map(fn ($p) => $p['og_site_name'] ?? '', $pages)));
        foreach ($names as $n) {
            if (preg_match(self::DEFAULT_SITE_NAMES, $n)) {
                $this->add('site', 'page', 'Spójność i wiarygodność', 'srednie', 'Przy udostępnianiu strona przedstawia się jako „'.$n.'”',
                    'W kodzie (og:site_name) jest domyślna nazwa witryny. Gdy ktoś wklei link na Facebooku, Messengerze czy WhatsAppie, w podglądzie pojawi się „'.$n.'” zamiast nazwy firmy – na wszystkich podstronach.',
                    'Wpisz nazwę firmy w ustawieniach witryny (WordPress: Ustawienia → Ogólne → Nazwa witryny) albo we wtyczce SEO (sekcja Social / Open Graph).', 'og:site_name = "'.$n.'"');
            }
        }

        // E-mail w innej domenie niż strona
        $free = '/@(gmail|googlemail|wp|o2|onet|op|interia|poczta|yahoo|outlook|hotmail|icloud|tlen|vp|gazeta)\./i';
        $foreign = array_filter(array_unique(array_merge(...array_values(array_map(fn ($p) => $p['emails'], $pages)))),
            fn ($e) => str_contains($e, '@') && ! preg_match($free, $e) && $this->bare(substr(strrchr($e, '@'), 1)) !== $this->siteHost);
        if ($foreign) {
            $this->add('site', 'page', 'Spójność i wiarygodność', 'srednie', 'Adres e-mail w innej domenie niż strona',
                'Strona działa pod '.$this->siteHost.', a kontakt mailowy to '.implode(', ', $foreign).'. Klient może nie wiedzieć, czy to ta sama firma – zwłaszcza jeśli marka na stronie brzmi inaczej niż domena e-maila.',
                'Ujednolić markę (np. adres w domenie strony) albo dopisać jedno zdanie, że strona to marka firmy z domeny e-maila.', implode(', ', $foreign), [], true);
        }

        // Linki www / bez www i http na stronie https
        $mixed = [];
        $plainHttp = [];
        foreach ($pages as $role => $p) {
            $srcs = array_merge(array_column(array_filter($p['links'], fn ($l) => $l['internal']), 'abs'),
                array_filter(array_map(fn ($i) => $this->absolute($i['src'], $p['final_url']), $p['images'])));
            foreach ($srcs as $u) {
                $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));
                if ($h === '' || $this->bare($h) !== $this->siteHost) {
                    continue;
                }
                if (str_starts_with($h, 'www.') !== $this->siteWww) {
                    $mixed[$u] = $role;
                }
                if ($this->scheme === 'https' && str_starts_with((string) $u, 'http://')) {
                    $plainHttp[$u] = $role;
                }
            }
        }
        if (count($mixed) >= 2) {
            $form = $this->siteWww ? 'bez „www”' : 'z „www”';
            $this->add('site', 'code', 'Kod strony', 'srednie', 'Linki i obrazy raz z „www”, raz bez',
                'Strona działa pod adresem '.($this->siteWww ? 'z www' : 'bez www').', a '.$this->plural(count($mixed), 'link lub obrazek', 'linki i obrazki', 'linków i obrazków').' na sprawdzonych podstronach prowadzi pod adres '.$form.'. Każde takie kliknięcie i każdy obrazek przechodzi przez dodatkowe przekierowanie – strona ładuje się wolniej (szczególnie na telefonie), a przy przekierowaniach łatwo o pomyłkę, dokąd prowadzi link.',
                'Zamienić adresy na docelowe (WordPress: wtyczka Better Search Replace – zamiana starego adresu na właściwy w bazie).', implode("\n", array_slice(array_keys($mixed), 0, 4)), array_slice(array_keys($mixed), 0, 10));
        }
        if ($plainHttp) {
            $this->add('site', 'code', 'Kod strony', 'srednie', 'Linki wewnętrzne przez http:// zamiast https://',
                $this->plural(count($plainHttp), 'link lub obrazek prowadzi', 'linki lub obrazki prowadzą', 'linków lub obrazków prowadzi').' przez niezabezpieczony adres http:// – przeglądarka musi przekierować, a obrazki mogą zostać zablokowane jako „mieszana treść”.',
                'Zmienić adresy na https:// (zamiana w bazie danych).', implode("\n", array_slice(array_keys($plainHttp), 0, 3)), array_slice(array_keys($plainHttp), 0, 10));
        }

        // Telefony: zapis linków tel:
        $tels = [];
        foreach ($pages as $p) {
            foreach ($p['links'] as $l) {
                if (stripos($l['href'], 'tel:') === 0) {
                    $tels[$l['href']] = true;
                }
            }
        }
        $bad = array_filter(array_keys($tels), fn ($t) => preg_match('/^tel:(%20|\s)|%20%20|\s{2,}/i', $t));
        $noCountry = array_filter(array_keys($tels), fn ($t) => ! str_contains(rawurldecode($t), '+') && strlen(preg_replace('/\D/', '', $t)) === 9);
        if ($bad) {
            $this->add('site', 'code', 'Kod strony', 'srednie', 'Błędnie zapisane linki telefoniczne',
                'Część przycisków „zadzwoń” ma w kodzie spacje na początku lub podwójne spacje w numerze. Większość telefonów sobie poradzi, ale część aplikacji (tablety, komputery, komunikatory) może nie rozpoznać numeru.',
                'Ujednolicić wszystkie linki do formatu tel:+48XXXXXXXXX (bez spacji).', implode("\n", array_slice($bad, 0, 4)));
        } elseif ($noCountry) {
            $this->add('site', 'code', 'Kod strony', 'drobne', 'Linki telefoniczne bez numeru kierunkowego +48',
                'Numer w linku „zadzwoń” nie ma +48. Z polskiej karty SIM zadziała, z zagranicznej lub z komunikatora na komputerze – nie zawsze.',
                'Zapisać linki jako tel:+48XXXXXXXXX.', implode("\n", array_slice($noCountry, 0, 3)));
        }
        $variants = [];
        foreach ($pages as $p) {
            if (preg_match_all('/(?:\+48[\s-]?)?\(?\d{2,3}\)?[\s-]?\d{3}[\s-]?\d{2,3}[\s-]?\d{0,3}/', $p['text'], $m)) {
                foreach ($m[0] as $num) {
                    $digits = substr(preg_replace('/\D/', '', $num), -9);
                    if (strlen($digits) === 9 && preg_match('/[\s-]/', $num)) {
                        $variants[$digits][trim($num)] = true;
                    }
                }
            }
        }
        $multi = array_filter($variants, fn ($v) => count($v) >= 3);
        if ($multi) {
            $d = array_key_first($multi);
            $this->add('site', 'page', 'Spójność i wiarygodność', 'drobne', 'Ten sam numer telefonu zapisany na kilka sposobów',
                'Numer pojawia się w różnych formatach: „'.implode('”, „', array_slice(array_keys($multi[$d]), 0, 4)).'”. Drobiazg, ale wygląda niechlujnie.',
                'Ujednolicić zapis, np. „+48 500 600 700”.');
        }

        // Rok w stopce
        $footerText = html_entity_decode(strip_tags(preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $home['raw'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/(?<![\w@.])@\s?(20\d\d)\b/u', $footerText, $m)) {
            $this->add('site', 'page', 'Spójność i wiarygodność', 'drobne', 'W stopce „@'.$m[1].'” zamiast „©'.$m[1].'”',
                'Znak „@” w nocie o prawach autorskich to literówka – powinno być „©”.', 'Poprawić tekst stopki.', '@'.$m[1]);
        } elseif (preg_match('/(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?(20\d\d)/iu', $footerText, $m) && (int) $m[1] < (int) date('Y') - 1) {
            $this->add('site', 'page', 'Spójność i wiarygodność', 'drobne', 'Nieaktualny rok w stopce (© '.$m[1].')',
                'Stary rok w stopce sugeruje, że nikt nie dba o stronę.', 'Ustawić rok automatycznie (shortcode/rok bieżący) albo usunąć rok.');
        }

        // Weryfikacja Google z podwójnym prefiksem
        foreach ($home['site_verification'] as $v) {
            if (str_starts_with(strtolower($v), 'google-site-verification')) {
                $this->add('site', 'code', 'Kod strony', 'drobne', 'Znacznik weryfikacji Google ma podwójny prefiks',
                    'W kodzie jest content="'.mb_substr($v, 0, 34).'…" – w tej postaci weryfikacja przez znacznik nie zadziała. Jeśli Search Console jest zweryfikowane inną metodą (np. DNS), nie ma problemu.',
                    'W polu weryfikacji wpisać sam kod (bez „google-site-verification=”).', 'content="'.mb_substr($v, 0, 40).'…"', [], true);
            }
        }

        // Piksel Facebooka w <noscript> – ładuje się bez zgody
        if (preg_match('#<noscript[^>]*>(?:(?!</noscript>).)*facebook\.com/tr\?(?:(?!</noscript>).)*</noscript>#is', $home['raw'])) {
            $this->add('site', 'code', 'Pomiar i zgody (RODO)', 'srednie', 'Piksel Facebooka w wersji bez JavaScript ładuje się bez zgody',
                'Obrazek facebook.com/tr jest wstawiony bezpośrednio w kod strony (w znaczniku noscript). W tej wersji ładuje się bez względu na decyzję w banerze cookies.',
                'Sprawdzić z osobą od RODO, czy wtyczka piksela jest spięta z banerem zgód, i usunąć wersję noscript albo warunkować ją zgodą.', '<noscript><img src="https://www.facebook.com/tr?id=…">', [], true);
        }

        // Różne wersje tych samych plików na podstronach (stara pamięć podręczna)
        $versions = [];
        foreach ($pages as $role => $p) {
            if (preg_match_all('#(?:href|src)=["\']([^"\']+?/([\w.-]+\.(?:css|js)))\?ver=([\w.-]+)#i', $p['raw'], $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    if (preg_match('/^\d+\.\d+(\.\d+)?$/', $hit[3])) {
                        $key = preg_replace('#^https?://[^/]+#', '', $hit[1]);
                        $versions[$key][$hit[3]][] = self::ROLES[$role];
                    }
                }
            }
        }
        $diff = array_filter($versions, fn ($v) => count($v) > 1);
        if ($diff) {
            $file = array_key_first($diff);
            $desc = implode('; ', array_map(fn ($ver, $roles) => $ver.' – '.implode(', ', array_unique($roles)), array_keys($diff[$file]), $diff[$file]));
            $plugin = preg_match('#/plugins/([^/]+)/#', $file, $pm) ? $pm[1] : basename($file);
            $this->add('site', 'code', 'Kod strony', 'srednie', 'Różne wersje plików na podstronach ('.$plugin.')',
                'Ten sam plik ma na podstronach różne wersje ('.$desc.'). To zwykle oznacza, że część stron jest serwowana ze starej pamięci podręcznej (cache) – mogą mieć nieaktualne style lub skrypty.',
                'Wyczyścić cache (wtyczka cache, CDN, hosting) i wygenerować pliki CSS na nowo (Elementor: Narzędzia → Regeneruj pliki).', basename($file).': '.$desc);
        }

        // Pliki z eksportu Webflow / Canvy itp.
        $all = array_merge(...array_values(array_map(fn ($p) => $p['images'], $pages)));
        $webflow = array_filter($all, fn ($i) => preg_match('#/[0-9a-f]{24}_[^/]+$#', (string) parse_url($i['src'], PHP_URL_PATH)));
        if (count($webflow) >= 3) {
            $this->add('site', 'code', 'Kod strony', 'drobne', 'Nazwy plików graficznych z eksportu z innego systemu',
                'Część obrazków ma nazwy w stylu „'.basename((string) parse_url(reset($webflow)['src'], PHP_URL_PATH)).'” – strona była migrowana (np. z Webflow). To nie błąd, ale przy porządkach warto zastąpić najcięższe obrazki lżejszymi wersjami WebP o opisowych nazwach.',
                'Przy okazji porządków podmienić najcięższe pliki na WebP.');
        }
    }

    // ------------------------------------------------------------------ sprawdzenia: pojedyncza podstrona

    private function checkPage(string $role, array $p): void
    {
        $url = $p['final_url'];
        $plText = $p['lang'] === '' || str_starts_with($p['lang'], 'pl');

        // Ten sam adres pod różnymi pozycjami (np. dwie oferty prowadzą do jednej karty)
        $groups = [];
        foreach ($p['links'] as $l) {
            if (! $l['internal'] || $l['zone'] !== 'main' || mb_strlen($l['text']) < 12 || preg_match(self::GENERIC_LINK, $l['text'])) {
                continue;
            }
            $key = rtrim(preg_replace('#^https?://(www\.)?#', '', (string) $l['abs']), '/');
            $groups[$key][mb_strtolower($l['text'])] = $l['text'];
        }
        foreach ($groups as $target => $texts) {
            if (count($texts) < 2) {
                continue;
            }
            $t = array_values($texts);
            similar_text(mb_strtolower($t[0]), mb_strtolower($t[1]), $pct);
            if ($pct < 60) {
                continue; // zupełnie różne teksty (np. obrazek + tytuł) – pomijamy
            }
            $this->add($role, 'page', 'Linki', $pct >= 80 ? 'pilne' : 'srednie', 'Różne pozycje prowadzą pod ten sam adres',
                'Kafelki/linki „'.$t[0].'” i „'.$t[1].'” prowadzą pod ten sam adres (…/'.basename($target).'/). Jeśli to dwie różne oferty, klient, który kliknie jedną, zobaczy drugą – np. inny poziom albo inną cenę.',
                'Sprawdzić, dokąd powinien prowadzić każdy kafelek, i poprawić link.', 'https://'.$target, ['https://'.$target], true);
        }

        // Przyciski, które nic nie robią
        $dead = array_filter($p['links'], fn ($l) => $l['zone'] === 'main' && $l['button'] && in_array($l['href'], ['#', '', 'javascript:void(0)', 'javascript:void(0);', 'javascript:;'], true) && $l['text'] !== '');
        if ($dead) {
            $this->add($role, 'page', 'Linki', 'srednie', 'Przyciski bez adresu',
                'Przyciski „'.implode('”, „', array_slice(array_unique(array_column($dead, 'text')), 0, 4)).'” mają w kodzie pusty link („#”). Jeśli nie otwierają okienka, kliknięcie nic nie robi.',
                'Podpiąć właściwy adres (formularz, telefon, oferta).', null, [$url], true);
        }

        // Obrazy: nazwy plików zamiast opisów, ten sam opis wielu obrazów, robocze pliki
        $fileAlts = array_values(array_unique(array_filter(array_column($p['images'], 'alt'), fn ($a) => $a && (preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $a) || (! str_contains($a, ' ') && preg_match('/[-_]/', $a) && mb_strlen($a) > 5)))));
        if (count($fileAlts) >= 2) {
            $this->add($role, 'page', 'Obrazy i grafiki', 'drobne', 'Opisy obrazków to nazwy plików',
                'Obrazki mają podpisy w stylu „'.implode('”, „', array_slice($fileAlts, 0, 3)).'”. Czytniki ekranu przeczytają je na głos, a Google nie dowie się, co przedstawiają.',
                'Uzupełnić opisy (tekst alternatywny) – krótko, co jest na obrazku.', implode(', ', array_slice($fileAlts, 0, 5)));
        }
        $altCount = array_count_values(array_filter(array_column($p['images'], 'alt'), fn ($a) => $a && mb_strlen($a) > 2));
        $repeated = array_filter($altCount, fn ($n) => $n >= 4);
        if ($repeated) {
            $a = array_key_first($repeated);
            $this->add($role, 'page', 'Obrazy i grafiki', 'drobne', 'Wiele obrazków ma ten sam opis „'.$a.'”',
                $this->plural($repeated[$a], 'obrazek', 'obrazki', 'obrazków').' na tej podstronie ma identyczny opis „'.$a.'” – nie wiadomo, czym się różnią (np. logotypy partnerów bez nazw).',
                'Opisać każdy obrazek osobno (np. nazwa partnera).');
        }
        $draft = array_filter($p['images'], fn ($i) => preg_match('/(zrzut[-_ ]ekranu|screenshot|screen[-_]shot|\/images\.jpe?g|whatsapp[-_]image|img_\d{4}|wpis[-_]na[-_]facebook|[-_]mem[-_.]|placeholder|lorem|template|szablon|canva)/i', (string) parse_url($i['src'], PHP_URL_PATH)));
        if ($draft) {
            $names = array_slice(array_unique(array_map(fn ($i) => urldecode(basename((string) parse_url($i['src'], PHP_URL_PATH))), $draft)), 0, 4);
            $this->add($role, 'page', 'Obrazy i grafiki', 'srednie', 'Grafiki wyglądające na robocze pliki',
                'Na podstronie są obrazki o nazwach: „'.implode('”, „', $names).'”. Zrzuty ekranu, posty z Facebooka czy pliki z szablonu wyglądają nieprofesjonalnie i bywają nieostre.',
                'Zastąpić je porządnymi zdjęciami lub logotypami (najlepiej własne zdjęcia zespołu, realizacji).', implode(', ', $names), [$url], true);
        }

        // Liczniki z zerami, puste punkty listy
        if ($p['zero_counters']) {
            $this->add($role, 'page', 'Treść widoczna dla klienta', 'srednie', 'Liczniki mają w kodzie wartość 0',
                $this->plural(count($p['zero_counters']), 'licznik', 'liczniki', 'liczników').' (np. liczba klientów, realizacji) ma w kodzie wartość 0 – liczba pojawia się tylko, jeśli zadziała animacja. Przy wolnym internecie, wyłączonym JavaScripcie albo dla Google strona pokazuje „0”.',
                'Wpisać docelowe liczby na stałe albo sprawdzić, czy animacja startuje od razu.', 'wartości docelowe: '.implode(', ', array_slice($p['zero_counters'], 0, 5)), [$url], true);
        }
        if ($p['empty_li'] > 0) {
            $this->add($role, 'page', 'Treść widoczna dla klienta', 'drobne', 'Pusty punkt na liście',
                'W treści jest pusty element listy ('.$p['empty_li'].') – może wyświetlać się samotna kropka lub ikona.',
                'Usunąć pusty punkt w edytorze.', null, [$url], true);
        }

        // Treść po angielsku / lorem ipsum
        if (preg_match('/lorem ipsum|dolor sit amet/i', $p['text'])) {
            $this->add($role, 'page', 'Treść widoczna dla klienta', 'pilne', 'Tekst zastępczy „Lorem ipsum” na stronie',
                'Na podstronie został tekst przykładowy z szablonu – klient widzi, że strona jest niedokończona.', 'Zastąpić właściwą treścią albo usunąć sekcję.', 'lorem ipsum', [$url]);
        }
        if ($this->isPolishSite($p)) {
            $english = array_filter($p['strings']['visible'], fn ($where, $t) => $this->isEnglish($t), ARRAY_FILTER_USE_BOTH);
            // to, co jest w menu i stopce, zgłaszamy raz – na stronie głównej
            if ($role !== 'home') {
                $english = array_filter($english, fn ($where) => $where === 'treść');
            }
            if ($english) {
                $list = array_map(fn ($t, $where) => '„'.mb_strimwidth($t, 0, 80, '…').'” ('.$where.')', array_keys($english), $english);
                $this->add($role, 'page', 'Treść widoczna dla klienta', count($english) >= 4 ? 'srednie' : 'drobne', 'Teksty po angielsku na polskiej stronie',
                    $this->plural(count($english), 'tekst widoczny', 'teksty widoczne', 'tekstów widocznych').' dla klienta jest po angielsku – zwykle to nieprzetłumaczone elementy szablonu, wtyczek lub formularza: '.implode(', ', array_slice($list, 0, 5)).'.',
                    'Przetłumaczyć w ustawieniach motywu/wtyczki, w edytorze (Elementor) albo wtyczką Loco Translate.', implode("\n", array_slice(array_keys($english), 0, 10)), [$url]);
            }
        }

        $this->checkHeadings($role, $p);
        $this->checkA11y($role, $p);

        // Nagłówki i FAQ zapisane Każdym Słowem Wielką Literą
        $titleCase = array_values(array_filter(array_merge(array_column(array_filter($p['headings'], fn ($h) => $h['level'] >= 2), 'text'), $p['faq']), function ($t) {
            $words = array_filter(preg_split('/\s+/u', preg_replace('/[^\p{L}\s]/u', '', $t)), fn ($w) => mb_strlen($w) >= 3);
            if (count($words) < 3 || mb_strtoupper($t) === $t) {
                return false;
            }

            return ! array_filter($words, fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)) !== mb_substr($w, 0, 1));
        }));
        if ($plText && count($titleCase) >= 2) {
            $this->add($role, 'page', 'Treść widoczna dla klienta', 'drobne', 'Nagłówki zapisane jak po angielsku (Każde Słowo Wielką Literą)',
                'Np. „'.implode('”, „', array_slice($titleCase, 0, 3)).'”. Po polsku wielką literą piszemy tylko pierwsze słowo i nazwy własne.',
                'Poprawić zapis nagłówków/pytań.', implode(' | ', array_slice($titleCase, 0, 5)));
        }

        // Formularze
        foreach ($p['forms'] as $f) {
            $this->checkForm($role, $url, $f, $p);
        }

        // Kontakt: dane firmy bez adresu
        if ($role === 'contact') {
            $hasCompanyIds = preg_match('/\b(NIP|REGON|KRS)\b/u', $p['text']);
            $hasAddress = preg_match('/\b\d{2}-\d{3}\b/u', $p['text']) || preg_match('/\b(ul\.|ulica|al\.|aleja|os\.|pl\.)\s+\p{Lu}/u', $p['text']);
            if ($hasCompanyIds && ! $hasAddress) {
                $this->add($role, 'page', 'Dane kontaktowe', 'srednie', 'Są NIP/REGON/KRS, ale brak adresu firmy',
                    'Na stronie kontaktowej są numery rejestrowe, ale nie ma adresu siedziby. Klient wybierający firmę (także działającą online) często sprawdza, gdzie formalnie działa.',
                    'Dodać adres siedziby (może być bez mapy, jeśli firma nie przyjmuje na miejscu).', null, [$url]);
            }
            if (! array_filter($p['links'], fn ($l) => stripos($l['href'], 'tel:') === 0)) {
                $this->add($role, 'page', 'Dane kontaktowe', 'srednie', 'Na stronie kontaktowej nie ma klikalnego numeru telefonu',
                    'Numer nie jest linkiem „tel:” – na telefonie trzeba go przepisywać.', 'Zamienić numer na link tel:+48…', null, [$url]);
            }
        }

        // Nagłówki w pogrubieniu
        $inBold = array_filter($p['headings'], fn ($h) => $h['in_bold']);
        if (count($inBold) >= 2) {
            $this->add($role, 'code', 'Kod strony', 'drobne', 'Nagłówki zagnieżdżone w pogrubieniu',
                $this->plural(count($inBold), 'nagłówek', 'nagłówki', 'nagłówków').' jest w kodzie wewnątrz znacznika pogrubienia (np. „'.reset($inBold)['text'].'”). Wizualnie może to nie mieć znaczenia, ale utrudnia zmiany stylu i czytanie strony przez czytniki ekranu.',
                'Usunąć pogrubienie wokół nagłówków i ustawić wygląd w stylu nagłówka.', null, [$url]);
        }
    }

    private function checkForm(string $role, string $url, array $f, array $p): void
    {
        $inputs = array_filter($f['fields'], fn ($x) => ! in_array($x['type'], ['checkbox', 'radio'], true));
        if (count($inputs) < 2) {
            return; // wyszukiwarka, newsletter z jednym polem
        }
        $noLabel = array_filter($inputs, fn ($x) => ! $x['label_visible'] && $x['placeholder'] !== '');
        if (count($noLabel) >= 2) {
            $this->add($role, 'page', 'Formularz', 'srednie', 'Pola formularza bez widocznych etykiet',
                'Pola „'.implode('”, „', array_slice(array_column($noLabel, 'placeholder'), 0, 4)).'” mają tylko tekst w środku pola – znika, gdy klient zaczyna pisać. Na telefonie łatwo zapomnieć, co to było za pole; czytniki ekranu też mają z tym problem.',
                'Dodać widoczne etykiety nad polami (placeholder może zostać jako przykład).', null, [$url], true);
        }
        foreach ($inputs as $x) {
            $hint = mb_strtolower($x['name'].' '.$x['placeholder'].' '.$x['label']);
            if (preg_match('/tel|phone|telefon/u', $hint) && ! in_array($x['type'], ['tel', 'number'], true)) {
                $this->add($role, 'mobile', 'Formularze na telefonie', 'drobne', 'Pole telefonu nie włącza klawiatury numerycznej',
                    'Pole „'.($x['label'] ?: $x['placeholder'] ?: $x['name']).'” ma typ „'.$x['type'].'” zamiast „tel” – na telefonie otwiera się zwykła klawiatura z literami.',
                    'Zmienić typ pola na „Telefon” (tel) w ustawieniach formularza.', null, [$url]);

                break;
            }
        }
        foreach (array_filter($f['fields'], fn ($x) => $x['type'] === 'checkbox' && $x['label']) as $c) {
            if (preg_match('/regulamin|polityk|prywatno|rodo|przetwarzani/iu', $c['label']) && $c['consent_links'] === 0) {
                $hasTerms = (bool) array_filter($p['links'], fn ($l) => preg_match('/regulamin/iu', $l['text'].' '.$l['href']));
                $this->add($role, 'page', 'Formularz', 'srednie', 'Zgoda w formularzu bez linku do dokumentu',
                    'Treść zgody: „'.mb_substr($c['label'], 0, 160).'”. Dokument, na który się powołuje, nie jest podlinkowany'.(preg_match('/regulamin/iu', $c['label']) && ! $hasTerms ? ', a na stronie nie widać regulaminu' : '').'. Klient zgadza się na coś, czego nie może przeczytać.',
                    'Podlinkować politykę prywatności (i regulamin, jeśli istnieje) w treści zgody albo zmienić treść zgody.', mb_substr($c['label'], 0, 200), [$url]);
            }
        }
    }

    // ------------------------------------------------------------------ struktura nagłówków

    private function checkHeadings(string $role, array $p): void
    {
        $url = $p['final_url'];
        $hs = $p['headings'];
        $main = array_values(array_filter($hs, fn ($h) => $h['zone'] === 'main'));
        $h1 = array_values(array_filter($hs, fn ($h) => $h['level'] === 1 && ! $h['empty']));

        if (! $h1) {
            $this->add($role, 'page', 'Struktura nagłówków', 'srednie', 'Brak głównego nagłówka H1',
                'Podstrona nie ma nagłówka H1, który mówi klientowi, Google i asystentom AI, o czym jest strona'.($main ? ' – pierwszy nagłówek to H'.$main[0]['level'].' „'.$main[0]['text'].'”' : '').'.',
                'Ustawić główny tytuł podstrony jako H1 (Elementor: widżet Nagłówek → znacznik HTML H1).', null, [$url]);
        } elseif (count($h1) > 1) {
            $this->add($role, 'page', 'Struktura nagłówków', 'drobne', 'Kilka nagłówków H1 na jednej podstronie',
                'Podstrona ma '.count($h1).' nagłówki H1: „'.implode('”, „', array_map(fn ($h) => mb_strimwidth($h['text'], 0, 60, '…'), array_slice($h1, 0, 4))).'”. Główny temat strony się rozmywa.',
                'Zostawić jeden H1 (główny tytuł), pozostałe zmienić na H2.', null, [$url]);
        } elseif ($main && $main[0]['level'] !== 1 && $h1[0]['zone'] === 'main') {
            $before = array_slice(array_filter($main, fn ($h) => $h['level'] > 1), 0, 2);
            $this->add($role, 'page', 'Struktura nagłówków', 'drobne', 'Nagłówek H1 nie jest pierwszy',
                'Przed głównym tytułem (H1 „'.$h1[0]['text'].'”) są inne nagłówki: „'.implode('”, „', array_map(fn ($h) => 'H'.$h['level'].' '.mb_strimwidth($h['text'], 0, 50, '…'), $before)).'”. Zwykle to podtytuł lub hasło sformatowane jako nagłówek.',
                'Zmienić elementy nad tytułem na zwykły tekst albo ustawić H1 jako pierwszy.', null, [$url]);
        }

        $skips = [];
        $prev = 0;
        foreach (array_filter($main, fn ($h) => ! $h['empty']) as $h) {
            if ($prev && $h['level'] > $prev + 1) {
                $skips[] = 'H'.$prev.' → H'.$h['level'].' „'.mb_strimwidth($h['text'], 0, 50, '…').'”';
            }
            $prev = $h['level'];
        }
        if ($skips) {
            $this->add($role, 'page', 'Struktura nagłówków', 'drobne', 'Przeskoki poziomów nagłówków',
                'Nagłówki nie tworzą porządnego spisu treści – poziomy są pomijane ('.$this->plural(count($skips), 'miejsce', 'miejsca', 'miejsc').'), zwykle dlatego, że poziom nagłówka dobrano do wielkości liter, a nie do struktury. Czytniki ekranu i Google gorzej rozumieją, co jest działem, a co podpunktem.',
                'Ustawiać poziomy po kolei (H2 dla sekcji, H3 dla elementów w sekcji); wygląd zmieniać stylem, nie poziomem.', implode("\n", array_slice($skips, 0, 5)), [$url]);
        }
        $empty = count(array_filter($hs, fn ($h) => $h['empty']));
        if ($empty) {
            $this->add($role, 'page', 'Struktura nagłówków', 'drobne', 'Puste nagłówki w kodzie',
                $this->plural($empty, 'nagłówek jest pusty', 'nagłówki są puste', 'nagłówków jest pustych').' (bez tekstu albo tylko z ikoną/obrazkiem bez opisu). Czytnik ekranu ogłasza „nagłówek” bez treści.',
                'Usunąć puste nagłówki albo wpisać tekst.', null, [$url]);
        }
        $long = array_filter($main, fn ($h) => mb_strlen($h['text']) > 110);
        if ($long) {
            $this->add($role, 'page', 'Struktura nagłówków', 'drobne', 'Całe zdania i akapity jako nagłówki',
                $this->plural(count($long), 'nagłówek ma', 'nagłówki mają', 'nagłówków ma').' ponad 110 znaków (np. „'.mb_strimwidth(reset($long)['text'], 0, 90, '…').'”). To zwykle akapit sformatowany jako nagłówek dla wyglądu.',
                'Zamienić na zwykły tekst (akapit) i zostawić krótki nagłówek.', null, [$url]);
        }
        $chrome = array_filter($hs, fn ($h) => in_array($h['zone'], ['header', 'nav', 'footer'], true) && $h['level'] <= 2 && ! $h['empty']);
        if (count($chrome) >= 3) {
            $this->add($role, 'page', 'Struktura nagłówków', 'drobne', 'Nagłówki H1/H2 w menu lub stopce',
                'Elementy menu/stopki są oznaczone jako ważne nagłówki: „'.implode('”, „', array_map(fn ($h) => mb_strimwidth($h['text'], 0, 40, '…'), array_slice($chrome, 0, 4))).'”. Na każdej podstronie konkurują z jej właściwymi tytułami.',
                'Zmienić je na zwykły tekst lub niższy poziom (np. H4 / „div”).', null, [$url]);
        }
    }

    // ------------------------------------------------------------------ dostępność

    private function checkA11y(string $role, array $p): void
    {
        $a = $p['a11y'];
        if (count($a['unnamed_links']) >= 2 && $role === 'home' || count($a['unnamed_links']) >= 4) {
            $this->add($role, 'code', 'Dostępność', count($a['unnamed_links']) >= 5 ? 'srednie' : 'drobne', 'Linki bez nazwy (same ikony)',
                $this->plural(count($a['unnamed_links']), 'link nie ma', 'linki nie mają', 'linków nie ma').' żadnego tekstu ani opisu – zwykle ikony mediów społecznościowych, telefonu, strzałki. Czytnik ekranu przeczyta tylko „link”, a Google nie wie, dokąd prowadzi.',
                'Dodać opis (aria-label), np. „Facebook firmy”, „Zadzwoń”.', implode("\n", array_slice($a['unnamed_links'], 0, 5)), [$p['final_url']]);
        }
        if ($a['unnamed_buttons'] >= 2) {
            $this->add($role, 'code', 'Dostępność', 'drobne', 'Przyciski bez nazwy',
                $this->plural($a['unnamed_buttons'], 'przycisk', 'przyciski', 'przycisków').' (np. menu, strzałki slidera, zamykanie okienka) nie ma tekstu ani opisu – niewidoma osoba nie wie, co robią.',
                'Dodać aria-label do przycisków z samą ikoną.', null, [$p['final_url']]);
        }
        if ($a['iframes_no_title'] >= 1) {
            $this->add($role, 'code', 'Dostępność', 'drobne', 'Osadzone ramki bez tytułu',
                $this->plural($a['iframes_no_title'], 'osadzona ramka', 'osadzone ramki', 'osadzonych ramek').' (mapa, film, formularz) nie ma atrybutu title.',
                'Dodać title opisujący zawartość, np. „Mapa dojazdu”.', null, [$p['final_url']]);
        }
    }

    // ------------------------------------------------------------------ język

    private const EN_WORDS = ['the', 'and', 'you', 'your', 'our', 'with', 'for', 'this', 'that', 'are', 'is', 'to', 'of', 'in', 'on', 'we', 'us', 'be', 'by', 'all', 'from', 'more', 'read',
        'about', 'here', 'click', 'please', 'now', 'get', 'contact', 'home', 'search', 'submit', 'send', 'name', 'message', 'learn', 'view', 'see', 'back', 'next', 'previous', 'close', 'open',
        'toggle', 'navigation', 'skip', 'content', 'share', 'follow', 'posted', 'comments', 'comment', 'reply', 'leave', 'required', 'field', 'phone', 'subject', 'address', 'first', 'last',
        'sign', 'log', 'login', 'cart', 'checkout', 'shop', 'add', 'sale', 'product', 'products', 'services', 'service', 'team', 'news', 'latest', 'load', 'scroll', 'top', 'go', 'play',
        'pause', 'slide', 'video', 'image', 'gallery', 'loading', 'thank', 'thanks', 'sent', 'error', 'invalid', 'enter', 'valid', 'accept', 'reject', 'settings', 'preferences', 'privacy',
        'policy', 'terms', 'conditions', 'powered', 'rights', 'reserved', 'free', 'best', 'quality', 'customer', 'customers', 'satisfied', 'happy', 'clients', 'years', 'experience', 'started',
        'book', 'call', 'today', 'what', 'how', 'why', 'who', 'where', 'when', 'can', 'will', 'not', 'yes', 'my', 'it', 'its', 'has', 'have', 'than', 'out', 'stock', 'items', 'item',
        'logo', 'icon', 'button', 'link', 'opens', 'new', 'window', 'tab', 'menu', 'main', 'mobile', 'footer', 'header', 'sidebar', 'widget', 'placeholder', 'carousel', 'slider', 'facebook',
        'instagram', 'page', 'pages', 'post', 'posts', 'category', 'categories', 'tag', 'tags', 'archive', 'archives', 'author', 'type', 'here', 'feedback', 'testimonial', 'testimonials', 'our'];

    private const PL_WORDS = ['i', 'w', 'z', 'na', 'się', 'nie', 'jest', 'że', 'dla', 'o', 'od', 'po', 'jak', 'czy', 'oraz', 'przez', 'lub', 'ze', 'są', 'tak', 'już', 'może',
        'tylko', 'także', 'jeśli', 'który', 'która', 'które', 'nasz', 'nasza', 'nasze', 'twój', 'pan', 'pani', 'zł', 'tel', 'ul', 'albo', 'oraz', 'przy', 'bez', 'pod', 'nad', 'za'];

    /** Pojedyncze słowa interfejsu, które na polskiej stronie zwykle oznaczają brak tłumaczenia. */
    private const EN_SINGLE = ['search', 'submit', 'send', 'close', 'next', 'previous', 'prev', 'loading', 'required', 'share', 'comments', 'login', 'cart', 'checkout', 'back', 'name', 'phone',
        'message', 'subject', 'reply', 'home', 'categories', 'tags', 'archives', 'author', 'toggle', 'play', 'pause', 'search…', 'search...', 'read more', 'learn more', 'more'];

    /** Wyrazy i nazwy, które są w polszczyźnie w porządku. */
    private const EN_ALLOWED = ['menu', 'blog', 'e-mail', 'email', 'newsletter', 'online', 'ok', 'start', 'faq', 'facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'google', 'premium',
        'standard', 'basic', 'pro', 'vip', 'spa', 'fitness', 'marketing', 'design', 'social media', 'e-commerce', 'b2b', 'cookies', 'logo', 'home office', 'call center', 'open space', 'team building'];

    private function isEnglish(string $s): bool
    {
        $s = trim($s);
        $low = mb_strtolower($s);
        if (mb_strlen($s) < 3 || preg_match('/[ąćęłńóśźż]/iu', $s) || in_array($low, self::EN_ALLOWED, true) || preg_match('/[@\/\\\\]|https?:|www\.|\d{3}/', $s)) {
            return false;
        }
        $words = preg_split("/[^a-z']+/", $low, -1, PREG_SPLIT_NO_EMPTY);
        if (! $words) {
            return false;
        }
        if (count($words) === 1 || in_array($low, self::EN_SINGLE, true)) {
            return in_array(rtrim($low, '.…:'), self::EN_SINGLE, true);
        }
        if (array_intersect($words, self::PL_WORDS)) {
            return false;
        }
        $en = count(array_filter($words, fn ($w) => in_array($w, self::EN_WORDS, true)));

        return count($words) <= 3 ? $en >= 2 || ($en === 1 && count($words) === 1) : $en >= 2 && $en / count($words) >= 0.3;
    }

    private function isPolishSite(array $p): bool
    {
        return str_starts_with($p['lang'] ?? '', 'pl') || preg_match_all('/[ąćęłńóśźż]/iu', $p['text'] ?? '') >= 15;
    }

    /** Teksty po angielsku ukryte w kodzie i błędny język strony – raz dla całej strony. */
    private function checkLanguage(array $pages): void
    {
        $home = $pages['home'];
        if (! $this->isPolishSite($home)) {
            return;
        }
        $wrongLang = array_filter($pages, fn ($p) => $p['lang'] !== '' && ! str_starts_with($p['lang'], 'pl') && preg_match_all('/[ąćęłńóśźż]/iu', $p['text']) >= 15);
        if ($wrongLang) {
            $lang = reset($wrongLang)['lang_raw'];
            $this->add('site', 'code', 'Język i teksty w kodzie', 'srednie', 'Strona jest oznaczona jako '.(str_starts_with(strtolower($lang), 'en') ? 'angielska' : 'obcojęzyczna').' (lang="'.$lang.'")',
                'Treść jest po polsku, ale kod mówi przeglądarce, że to język „'.$lang.'”. Chrome proponuje wtedy tłumaczenie strony, czytniki ekranu czytają polski tekst z angielską wymową, a Google może źle dobrać wyniki.',
                'Ustawić język witryny na polski (WordPress: Ustawienia → Ogólne → Język witryny; w wtyczkach wielojęzycznych – język domyślny).', '<html lang="'.$lang.'">', array_column($wrongLang, 'final_url'));
        }

        $found = [];
        foreach ($pages as $p) {
            foreach ($p['strings']['code'] as $t => $where) {
                if (! isset($found[$t]) && $this->isEnglish($t)) {
                    $found[$t] = $where;
                }
            }
        }
        if ($found) {
            $list = array_map(fn ($t, $where) => $where.': „'.mb_strimwidth($t, 0, 70, '…').'”', array_keys($found), $found);
            $this->add('site', 'code', 'Język i teksty w kodzie', count($found) >= 8 ? 'srednie' : 'drobne', 'Teksty po angielsku ukryte w kodzie',
                'Klient ich nie widzi od razu, ale słyszą je osoby z czytnikiem ekranu, widać je po najechaniu myszką, w podpowiedziach pól i w podglądzie linku – a Google je czyta. Znaleziono '
                .$this->plural(count($found), 'taki tekst', 'takie teksty', 'takich tekstów').', np. '.implode(', ', array_slice($list, 0, 4)).'.',
                'Przetłumaczyć teksty motywu i wtyczek (Loco Translate) oraz opisy obrazków i przycisków.', implode("\n", array_slice($list, 0, 12)));
        }
    }

    // ------------------------------------------------------------------ sprawdzenia: porównanie podstron

    private function checkCrossPage(array $pages): void
    {
        // Ten sam H1 na różnych podstronach (np. logo lub nazwa firmy jako H1)
        $h1s = [];
        foreach ($pages as $p) {
            foreach (array_filter($p['headings'], fn ($h) => $h['level'] === 1 && ! $h['empty']) as $h) {
                $h1s[mb_strtolower($h['text'])][$p['label']] = $h['text'];
            }
        }
        foreach ($h1s as $labels) {
            if (count($labels) >= 2) {
                $this->add('site', 'page', 'Struktura nagłówków', 'srednie', 'Ten sam nagłówek H1 na różnych podstronach',
                    'Podstrony '.implode(', ', array_keys($labels)).' mają identyczny H1 „'.reset($labels).'”. Zwykle to logo lub nazwa firmy w nagłówku strony oznaczona jako H1 – wtedy żadna podstrona nie ma własnego tytułu.',
                    'Zmienić H1 w nagłówku strony (logo) na zwykły tekst, a na każdej podstronie ustawić jej własny tytuł jako H1.');

                break;
            }
        }

        if (count($pages) < 3) {
            return;
        }
        $footers = array_map(fn ($p) => $p['footer_links'], $pages);
        $counts = array_count_values(array_merge(...array_values(array_map('array_unique', $footers))));
        foreach ($pages as $role => $p) {
            if (! $p['footer_links']) {
                continue;
            }
            $missing = array_filter(array_keys($counts), fn ($t) => $counts[$t] >= count($pages) - 1 && $counts[$t] >= 2 && ! in_array($t, $p['footer_links'], true) && mb_strlen($t) > 3);
            if ($missing) {
                $this->add($role, 'page', 'Linki', 'drobne', 'Stopka różni się od pozostałych podstron',
                    'W stopce tej podstrony brakuje linku „'.implode('”, „', array_slice($missing, 0, 3)).'”, który jest w stopce pozostałych stron. Zwykle to znak, że podstrona ma osobny, nieaktualny szablon stopki.',
                    'Ujednolicić stopkę (jeden wspólny szablon).', null, [$p['final_url']]);
            }
        }
    }

    // ------------------------------------------------------------------ mobile a desktop

    private function checkMobile(array $pages): void
    {
        $home = $pages['home'];

        if ($home['viewport'] === '') {
            $this->add('site', 'mobile', 'Wyświetlanie na telefonie', 'pilne', 'Brak ustawień dla telefonów (meta viewport)',
                'Bez znacznika viewport telefon wyświetla stronę jak pomniejszoną wersję komputerową – tekst jest mikroskopijny.',
                'Dodać <meta name="viewport" content="width=device-width, initial-scale=1">.');
        } elseif (preg_match('/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?\b/i', $home['viewport'])) {
            $this->add('site', 'mobile', 'Wyświetlanie na telefonie', 'drobne', 'Strona blokuje powiększanie na telefonie',
                'Ustawienia viewport („'.$home['viewport'].'”) nie pozwalają powiększyć strony palcami – utrudnienie dla osób słabiej widzących.',
                'Usunąć maximum-scale=1 / user-scalable=no.');
        }

        // Telefon w nagłówku na telefonie
        $headerTel = array_filter($home['links'], fn ($l) => stripos($l['href'], 'tel:') === 0 && in_array($l['zone'], ['header', 'nav'], true));
        $headerTelMobile = array_filter($headerTel, fn ($l) => ! $l['hidden_mobile']);
        $blocks = count($home['header_blocks']);
        if ($headerTel && ! $headerTelMobile) {
            $this->add('site', 'mobile', 'Nagłówek i menu', 'srednie', 'Na telefonie w nagłówku prawdopodobnie nie ma numeru do kliknięcia',
                ($blocks > 1 ? 'Nagłówek istnieje w kodzie w '.$blocks.' wariantach (osobno dla komputera i telefonu). ' : '').'Numer telefonu w nagłówku jest oznaczony jako ukryty na telefonach. A to dla klienta najszybsza droga kontaktu.',
                'Dodać do mobilnego nagłówka ikonę słuchawki z linkiem tel: albo przyklejony pasek „Zadzwoń”.', null, [], true);
        } elseif (! $headerTel && array_filter($pages, fn ($p) => array_filter($p['links'], fn ($l) => stripos($l['href'], 'tel:') === 0))) {
            $this->add('site', 'mobile', 'Nagłówek i menu', 'srednie', 'W nagłówku nie ma klikalnego numeru telefonu',
                'Numer telefonu jest na stronie, ale nie w nagłówku – na telefonie klient musi przewinąć do stopki albo wejść w Kontakt.',
                'Dodać ikonę słuchawki w nagłówku albo przyklejony pasek „Zadzwoń / Napisz” na dole ekranu.', null, [], true);
        } elseif (! array_filter($pages, fn ($p) => array_filter($p['links'], fn ($l) => stripos($l['href'], 'tel:') === 0))) {
            $this->add('site', 'mobile', 'Nagłówek i menu', 'srednie', 'Na stronie nie ma klikalnego numeru telefonu',
                'Żaden numer na sprawdzonych podstronach nie jest linkiem „tel:” – na telefonie trzeba go przepisywać.',
                'Zamienić numery na linki tel:+48… i dodać przycisk „Zadzwoń” w nagłówku.');
        }

        // Pozycje menu z „#” i długie podmenu
        $nav = array_filter($home['links'], fn ($l) => in_array($l['zone'], ['nav', 'header'], true) && $l['has_submenu']);
        $hash = array_values(array_unique(array_column(array_filter($nav, fn ($l) => in_array($l['href'], ['#', ''], true)), 'text')));
        if ($hash) {
            $this->add('site', 'mobile', 'Nagłówek i menu', 'srednie', 'Pozycje menu z rozwijaną listą nie mają adresu',
                'Pozycje „'.implode('”, „', array_slice($hash, 0, 5)).'” mają link „#”. Na komputerze rozwijają się po najechaniu myszką, ale na telefonie dotknięcie takiej pozycji może nic nie robić, jeśli menu nie ma osobnej strzałki do rozwijania.',
                'Sprawdzić na telefonie; w razie problemu włączyć rozwijanie po dotknięciu albo podpiąć stronę zbiorczą.', null, [], true);
        }
        $long = array_filter($nav, fn ($l) => $l['submenu_size'] >= 8);
        if ($long) {
            $desc = implode(', ', array_map(fn ($l) => '„'.$l['text'].'” – '.$l['submenu_size'], array_slice(array_values($long), 0, 4)));
            $this->add('site', 'mobile', 'Nagłówek i menu', 'srednie', 'Bardzo długie podmenu',
                'Podmenu mają dużo pozycji ('.$desc.'). Na telefonie to długa lista do przewijania, w której łatwo się zgubić.',
                'Pogrupować pozycje (np. 3–4 kategorie) albo zrobić stronę zbiorczą z kafelkami.');
        }

        // Przyciski „Umów/Zapisz/Zamów”, które tylko dzwonią – na komputerze martwe
        $callOnly = [];
        foreach ($pages as $p) {
            foreach ($p['links'] as $l) {
                if (stripos($l['href'], 'tel:') === 0 && preg_match('/umów|umow|zamów|zamow|zapisz|rezerw|wycen|zgłoś|zglos|skontaktuj|zarezerwuj|wizyt/iu', $l['text'])) {
                    $callOnly[$l['text']] = true;
                }
            }
        }
        if ($callOnly) {
            $this->add('site', 'mobile', 'Komputer', 'srednie', 'Przyciski „'.implode('”, „', array_slice(array_keys($callOnly), 0, 3)).'” tylko dzwonią',
                'Te przyciski są linkiem telefonicznym. Na telefonie to wygodne, ale na komputerze kliknięcie nic sensownego nie robi (otwiera Skype/FaceTime albo nic) – klient z laptopa nie ma jak się umówić bez przepisywania numeru.',
                'Na komputerze kierować ten przycisk do formularza, a na telefonie zostawić „Zadzwoń” (Elementor: osobne przyciski z widocznością mobile/desktop).', null, [], true);
        }

        // Bardzo długa stopka
        $footerCount = count(array_filter($home['links'], fn ($l) => $l['zone'] === 'footer'));
        if ($footerCount >= 40) {
            $this->add('site', 'mobile', 'Wyświetlanie na telefonie', 'drobne', 'Bardzo długa stopka',
                'Stopka ma '.$this->plural($footerCount, 'link', 'linki', 'linków').' – na telefonie to bardzo długie przewijanie na końcu każdej podstrony.',
                'Na telefonie zwinąć długie listy (np. dzielnice, usługi) w rozwijane bloki.', null, [], true);
        }
    }

    // ------------------------------------------------------------------ pomocnicze

    private function add(string $page, string $section, string $group, string $sev, string $title, string $text, ?string $fix = null, ?string $evidence = null, array $urls = [], bool $verify = false): void
    {
        $this->findings[] = compact('page', 'section', 'group', 'sev', 'title', 'text', 'fix', 'evidence', 'urls', 'verify') + ['source' => 'auto'];
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        return $n.' '.($n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many));
    }

    private function phones(array $pages): array
    {
        $out = [];
        foreach ($pages as $p) {
            foreach ($p['links'] as $l) {
                if (stripos($l['href'], 'tel:') === 0) {
                    $out[] = trim(rawurldecode(substr($l['href'], 4)));
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function builder(string $html): ?string
    {
        return match (true) {
            str_contains($html, 'elementor') => 'Elementor',
            str_contains($html, 'et_pb_') || str_contains($html, 'Divi') => 'Divi',
            str_contains($html, 'wpb_') => 'WPBakery',
            str_contains($html, 'wp-block-') => 'Gutenberg',
            str_contains($html, 'wixstatic') => 'Wix',
            str_contains($html, 'webflow') => 'Webflow',
            str_contains($html, 'cdn.shopify') => 'Shopify',
            default => null,
        };
    }

    private function zone(DOMNode $n): string
    {
        $zone = 'main';
        for ($p = $n->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            $cls = ' '.strtolower($p->getAttribute('class')).' ';
            $tag = $p->nodeName;
            if ($tag === 'footer' || str_contains($cls, 'elementor-location-footer') || preg_match('/[\s-]footer[\s_-]/', $cls) || $p->getAttribute('id') === 'footer') {
                return 'footer';
            }
            if ($tag === 'nav' || $p->getAttribute('role') === 'navigation' || str_contains($cls, 'nav-menu') || str_contains($cls, ' menu ')) {
                $zone = 'nav';
            } elseif ($zone === 'main' && ($tag === 'header' || str_contains($cls, 'elementor-location-header') || preg_match('/\ssite-header\s|\sheader\s/', $cls))) {
                $zone = 'header';
            }
        }

        return $zone;
    }

    private function hiddenOn(DOMNode $n, string $pattern): bool
    {
        for ($p = $n; $p instanceof DOMElement; $p = $p->parentNode) {
            if (preg_match($pattern, $p->getAttribute('class'))) {
                return true;
            }
        }

        return false;
    }

    private function clean(?string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function bare(string $host): string
    {
        return preg_replace('/^www\./i', '', strtolower($host));
    }

    private function rootUrl(): string
    {
        return $this->scheme.'://'.($this->siteWww ? 'www.' : '').$this->siteHost.'/';
    }

    private function sameUrl(string $a, string $b): bool
    {
        $n = fn ($u) => rtrim(preg_replace('#^https?://(www\.)?#i', '', strtolower($u)), '/');

        return $n($a) === $n($b);
    }

    private function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https').':'.$href;
        }
        if (preg_match('#^https?://#i', $href)) {
            return preg_replace('/#.*$/', '', $href);
        }
        $p = parse_url($base);
        if (empty($p['host'])) {
            return null;
        }
        $origin = ($p['scheme'] ?? 'https').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($href, '/')) {
            return preg_replace('/#.*$/', '', $origin.$href);
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');

        return preg_replace('/#.*$/', '', $origin.$dir.$href);
    }
}
