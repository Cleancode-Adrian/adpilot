<?php

namespace App\Services\SiteAudit;

use App\Models\Client;

/**
 * Buduje szkic pliku llms.txt (format llmstxt.org) z podstron znalezionych w audycie strony:
 * # Nazwa, > krótki opis, sekcje ## z listami „- [tytuł](adres): opis”.
 * Szkic do przejrzenia i poprawienia – opisy pochodzą z meta description podstron.
 */
class LlmsTxtGenerator
{
    /** Sekcja => słowa w adresie (pierwszy pasujący wygrywa). „Optional” ma w specyfikacji specjalne znaczenie: można pominąć. */
    private const SECTIONS = [
        'Optional' => ['polityka', 'privacy', 'prywatnosc', 'regulamin', 'terms', 'cookies', 'rodo', 'gdpr'],
        'Kontakt' => ['kontakt', 'contact'],
        'Oferta' => ['oferta', 'uslug', 'usług', 'service', 'produkt', 'product', 'sklep', 'shop', 'cennik', 'pricing', 'pakiet'],
        'O firmie' => ['o-nas', 'o-mnie', 'about', 'zespol', 'team', 'firma', 'realizacje', 'portfolio', 'opinie', 'referencje', 'case'],
        'Pomoc i FAQ' => ['faq', 'pytania', 'pomoc', 'help', 'dostawa', 'shipping', 'zwrot', 'platnosc', 'reklamac'],
        'Blog i poradniki' => ['blog', 'porad', 'artykul', 'aktualnosc', 'news', 'wpis', 'baza-wiedzy', 'knowledge'],
    ];

    private const ORDER = ['Oferta', 'O firmie', 'Pomoc i FAQ', 'Blog i poradniki', 'Strony', 'Kontakt', 'Optional'];

    /** Adresy pomijane: koszyk, konto, archiwa, stronicowanie, kanały. */
    private const SKIP = '#/(koszyk|cart|zamowienie|checkout|moje-konto|my-account|wp-login|wp-admin|feed|tag|category|kategoria|author|autor|page/\d+|strona/\d+)(/|$)|[?]#i';

    private const MAX_LINKS = 60;

    private const MAX_PER_SECTION = 20;

    /**
     * @param  list<array{url:string,status:int,title?:string,description?:string,noindex?:bool,h1?:array}>  $pages
     */
    public function generate(Client $client, array $pages): string
    {
        $root = rtrim($client->website_url, '/').'/';
        $ok = array_values(array_filter($pages, fn ($p) => ($p['status'] ?? 0) === 200 && empty($p['noindex']) && ! empty($p['title'])));

        $home = collect($ok)->first(fn ($p) => rtrim($p['url'], '/').'/' === $root);
        $suffix = $this->commonTitleSuffix(array_column($ok, 'title'));
        $name = $client->name;
        $summary = trim((string) ($home['description'] ?? '')) ?: ($client->industry ? "{$name} – {$client->industry}." : null);

        $sections = [];
        $seen = [];
        foreach ($ok as $p) {
            $url = $p['url'];
            $key = rtrim(strtolower($url), '/');
            if (isset($seen[$key]) || rtrim($url, '/').'/' === $root || preg_match(self::SKIP, (string) parse_url($url, PHP_URL_PATH).(parse_url($url, PHP_URL_QUERY) ? '?' : ''))) {
                continue;
            }
            $seen[$key] = true;
            $sections[$this->section($url)][] = $p;
        }

        $out = ["# {$name}", ''];
        if ($summary) {
            $out[] = '> '.$this->oneLine($summary, 300);
            $out[] = '';
        }
        $intro = [];
        if ($client->industry) {
            $intro[] = "Branża: {$client->industry}.";
        }
        $intro[] = 'Strona: '.rtrim($client->website_url, '/');
        $out[] = implode(' ', $intro);
        $out[] = '';

        $total = 0;
        foreach (self::ORDER as $section) {
            $items = $sections[$section] ?? [];
            if (! $items || $total >= self::MAX_LINKS) {
                continue;
            }
            // najkrótsze adresy (najważniejsze podstrony) najpierw
            usort($items, fn ($a, $b) => substr_count(parse_url($a['url'], PHP_URL_PATH) ?? '', '/') <=> substr_count(parse_url($b['url'], PHP_URL_PATH) ?? '', '/') ?: strcmp($a['url'], $b['url']));
            $items = array_slice($items, 0, min(self::MAX_PER_SECTION, self::MAX_LINKS - $total));
            $total += count($items);

            $out[] = "## {$section}";
            $out[] = '';
            foreach ($items as $p) {
                $title = $this->cleanTitle($p['title'], $suffix);
                $desc = trim((string) ($p['description'] ?? ''));
                $out[] = '- ['.str_replace(['[', ']'], ['(', ')'], $title).']('.$p['url'].')'.($desc !== '' ? ': '.$this->oneLine($desc, 160) : '');
            }
            $out[] = '';
        }

        return rtrim(implode("\n", $out))."\n";
    }

    private function section(string $url): string
    {
        $path = strtolower(urldecode((string) parse_url($url, PHP_URL_PATH)));
        foreach (self::SECTIONS as $section => $words) {
            foreach ($words as $w) {
                if (str_contains($path, $w)) {
                    return $section;
                }
            }
        }

        return 'Strony';
    }

    /** Wspólna końcówka tytułów, np. „ – Firma” albo „ | Firma” – usuwamy ją z nazw linków. */
    private function commonTitleSuffix(array $titles): ?string
    {
        $counts = [];
        foreach ($titles as $t) {
            if (preg_match('/\s[|–—\-•·]\s[^|–—•·]{2,60}$/u', $t, $m)) {
                $counts[$m[0]] = ($counts[$m[0]] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = array_key_first($counts);

        return $top !== null && $counts[$top] >= max(2, count($titles) * 0.4) ? $top : null;
    }

    private function cleanTitle(string $title, ?string $suffix): string
    {
        if ($suffix && str_ends_with($title, $suffix) && mb_strlen($title) > mb_strlen($suffix) + 2) {
            $title = mb_substr($title, 0, mb_strlen($title) - mb_strlen($suffix));
        }

        return $this->oneLine($title, 100);
    }

    private function oneLine(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }
}
