<?php

namespace App\Services\WebAudit;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lista stron do audytu zbiorczego z dowolnego wejścia:
 * - wklejone adresy (po jednym w linii albo w zdaniu), z „https://” lub same domeny,
 * - komórki skopiowane z arkusza (tabulatory, komórki z kilkoma liniami),
 * - link do Arkusza Google albo Dokumentu Google udostępnionego „każdemu, kto ma link”.
 *
 * Adresy są grupowane po domenie: jedna pozycja = jedna strona + jej wskazane podstrony. Dopiski bez adresu
 * (np. „czekamy za nową stroną”) zostają jako notatka, a strona z samą notatką jest domyślnie odznaczona.
 */
class BatchListParser
{
    /** końcówki plików – „raport.pdf” to nie domena */
    private const NOT_TLD = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'html', 'htm', 'php', 'zip', 'js', 'css'];

    /** Sama domena (bez „https://”) liczy się, gdy ma znaną końcówkę albo ta sama domena występuje na liście z pełnym adresem – inaczej „ul.Polna” byłoby stroną. */
    private const TLDS = ['pl', 'com', 'eu', 'net', 'org', 'info', 'biz', 'io', 'co', 'de', 'uk', 'cz', 'sk', 'at', 'ch', 'fr', 'es', 'it', 'nl', 'be', 'se', 'no', 'dk', 'fi', 'lt', 'lv', 'ee',
        'ua', 'ro', 'hu', 'bg', 'gr', 'pt', 'ie', 'us', 'ca', 'au', 'tr', 'me', 'tv', 'cc', 'ai', 'app', 'dev', 'tech', 'online', 'store', 'shop', 'site', 'xyz', 'pro', 'legal', 'law',
        'technology', 'solutions', 'agency', 'studio', 'design', 'digital', 'media', 'group', 'company', 'expert', 'academy', 'education', 'center', 'clinic', 'dental', 'care', 'health',
        'finance', 'capital', 'estate', 'house', 'travel', 'tours', 'auto', 'cars', 'fit', 'life', 'live', 'world', 'global', 'international', 'cloud', 'systems', 'software', 'services',
        'consulting', 'marketing', 'events', 'photo', 'photography', 'art', 'blog', 'news', 'club', 'vip', 'top', 'one', 'space', 'zone', 'city', 'energy', 'eco', 'bio', 'farm', 'beauty'];

    private const GOOGLE = '~https?://docs\.google\.com/(spreadsheets|document)/d/(e/)?([\w-]+)[^\s"\'<>]*~i';

    /**
     * @return array{items: list<array{label: string, url: string, pages: list<string>, note: ?string, enabled: bool}>, source: ?string}
     */
    public function parse(string $input): array
    {
        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $rows = [];
        $source = null;

        // linki do Arkusza / Dokumentu Google – w ich miejsce wchodzi pobrana treść
        if (preg_match_all(self::GOOGLE, $input, $links, PREG_SET_ORDER)) {
            foreach (array_slice($links, 0, 3) as $l) {
                $source ??= mb_substr($l[0], 0, 500);
                array_push($rows, ...$this->fetchGoogle($l[0], strtolower($l[1]), $l[2] !== '', $l[3]));
            }
            $input = preg_replace(self::GOOGLE, ' ', $input);
        }
        // komórki skopiowane z arkusza (tabulatory, cudzysłowy wokół komórek z kilkoma liniami) albo zwykłe linie
        array_push($rows, ...(str_contains($input, "\t") ? $this->csvRows($input, "\t") : array_map(fn ($line) => [$line], explode("\n", $input))));

        // domeny, które na liście mają pełny adres – ich zapis bez „https://” też jest wtedy pewny
        $known = [];
        foreach ($rows as $cells) {
            if (preg_match_all('~https?://(?:www\.)?([a-z0-9.-]+)~i', implode("\n", $cells), $hm)) {
                $known += array_flip(array_map('strtolower', $hm[1]));
            }
        }

        $groups = [];
        foreach ($rows as $cells) {
            $text = implode("\n", array_map(fn ($c) => (string) $c, $cells));
            [$urls, $rest] = $this->tokens($text, $known);
            if (! $urls) {
                continue;
            }
            $first = null;
            foreach ($urls as $u) {
                $host = preg_replace('/^www\./', '', $u['host']);
                $first ??= $host;
                $groups[$host] ??= ['host' => $u['host'], 'full' => false, 'scheme' => 'https', 'urls' => [], 'notes' => []];
                if ($u['full'] && ! $groups[$host]['full']) { // pełny adres pokazuje właściwą postać domeny (z „www” lub bez)
                    $groups[$host] = ['host' => $u['host'], 'full' => true, 'scheme' => $u['scheme']] + $groups[$host];
                }
                $groups[$host]['urls'][rtrim($u['path'], '/').$u['query']] ??= $u;
            }
            $note = $this->note($rest);
            if ($note !== null && ! in_array($note, $groups[$first]['notes'], true)) {
                $groups[$first]['notes'][] = $note;
            }
        }

        $max = (int) config('adpilot.audit_batch.max_sites', 150);
        if (count($groups) > $max) {
            throw ValidationException::withMessages(['list' => 'Na liście jest '.count($groups).' stron – w jednym audycie zbiorczym może być najwyżej '.$max.'. Podziel listę na części.']);
        }

        $items = [];
        foreach ($groups as $bare => $g) {
            $base = $g['scheme'].'://'.$g['host'];
            $paths = array_keys($g['urls']);
            // strona główna: domena; a gdy na liście jest tylko wersja językowa (np. „firma.com/pl”) – ona
            $home = '';
            if (! in_array('', $paths, true)) {
                foreach ($paths as $p) {
                    if (preg_match('~^/[a-z]{2}(?:[-_][a-z]{2})?$~i', $p)) {
                        $home = $p;

                        break;
                    }
                }
            }
            $pages = [];
            foreach ($g['urls'] as $key => $u) {
                if ($key !== $home && $key !== '') {
                    $pages[] = $base.$u['path'].$u['query'];
                }
            }
            $extra = max(0, count($pages) - PageInspector::KEY_PAGES);
            $note = implode(' · ', $g['notes']).($extra ? ($g['notes'] ? ' · ' : '').'pominięto '.$extra.' – najwyżej '.PageInspector::KEY_PAGES.' podstron na stronę' : '');
            $items[] = [
                'label' => $bare.$home,
                'url' => $base.($home !== '' ? $home.'/' : ''),
                'pages' => array_slice($pages, 0, PageInspector::KEY_PAGES),
                'note' => $note !== '' ? mb_strimwidth($note, 0, 480, '…') : null,
                'enabled' => ! ($g['notes'] && ! $pages), // sama domena z dopiskiem („czekamy za nową stroną”) – do decyzji
            ];
        }

        return ['items' => $items, 'source' => $source];
    }

    /**
     * Adresy z tekstu: pełne (https://…) i same domeny (firma.pl, firma.pl/oferta).
     *
     * @return array{0: list<array{host: string, scheme: string, path: string, query: string, full: bool}>, 1: string}
     */
    private function tokens(string $text, array $known = []): array
    {
        $out = [];
        $add = function (string $raw, bool $full) use (&$out) {
            $raw = rtrim($raw, ".,;:!?)]}'\"");
            $p = parse_url($full ? $raw : 'https://'.$raw);
            $host = strtolower((string) ($p['host'] ?? ''));
            // tylko zwykłe domeny – bez adresów IP i nazw lokalnych
            if (! preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$~', $host) || str_ends_with($host, '.google.com')) {
                return;
            }
            $out[] = ['host' => $host, 'scheme' => strtolower($p['scheme'] ?? 'https') === 'http' ? 'http' : 'https', 'path' => $p['path'] ?? '',
                'query' => isset($p['query']) ? '?'.$p['query'] : '', 'full' => $full];
        };

        $rest = preg_replace_callback('~https?://[^\s"\'<>]+~iu', function ($m) use ($add) {
            $add($m[0], true);

            return ' ';
        }, $text);
        $rest = preg_replace_callback('~(?<![@\w./-])((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,24}))(/[^\s"\'<>]*)?(?![\w@])~iu', function ($m) use ($add, $known) {
            $host = preg_replace('/^www\./', '', strtolower($m[1]));
            if (in_array(strtolower($m[2]), self::NOT_TLD, true) || $host === 'm.in' || ! (in_array(strtolower($m[2]), self::TLDS, true) || isset($known[$host]))) {
                return $m[0];
            }
            $add($m[0], false);

            return ' ';
        }, (string) $rest);

        return [$out, (string) $rest];
    }

    /** Dopisek z wiersza (to, co zostało po wyjęciu adresów): bez numerów porządkowych i znaków interpunkcyjnych. */
    private function note(string $rest): ?string
    {
        $parts = [];
        foreach (preg_split('/\n+/', $rest) as $part) {
            $part = preg_replace('/^[\s,;:.\-–"\']+|[\s,;:.\-–"\']+$/u', '', (string) preg_replace('/\s+/u', ' ', $part));
            if (preg_match_all('/\p{L}/u', $part) >= 3) {
                $parts[] = $part;
            }
        }

        return $parts ? mb_strimwidth(implode(' · ', array_unique($parts)), 0, 300, '…') : null;
    }

    /** @return list<list<string>> */
    private function csvRows(string $content, string $delimiter): array
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        $rows = [];
        while (($row = fgetcsv($h, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(fn ($c) => (string) $c, $row);
            }
        }
        fclose($h);

        return $rows;
    }

    /** Arkusz → CSV, dokument → tekst. Działa dla plików udostępnionych „każdemu, kto ma link” (albo opublikowanych w internecie). */
    private function fetchGoogle(string $url, string $kind, bool $published, string $id): array
    {
        $gid = preg_match('/[?&#]gid=(\d+)/', $url, $m) ? $m[1] : null;
        $export = match (true) {
            $kind === 'document' => "https://docs.google.com/document/d/{$id}/export?format=txt",
            $published => "https://docs.google.com/spreadsheets/d/e/{$id}/pub?output=csv".($gid !== null ? "&gid={$gid}" : ''),
            default => "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv".($gid !== null ? "&gid={$gid}" : ''),
        };
        $what = $kind === 'document' ? 'dokumentu' : 'arkusza';
        $fail = fn () => ValidationException::withMessages(['list' => "Nie udało się pobrać {$what} Google – prawdopodobnie nie jest udostępniony. "
            .'W pliku kliknij „Udostępnij” → „Każda osoba mająca link” (przeglądający) albo zaznacz komórki z adresami, skopiuj je i wklej tutaj.']);
        try {
            $r = Http::timeout(25)->get($export);
        } catch (Throwable) {
            throw $fail();
        }
        $type = strtolower((string) $r->header('Content-Type'));
        if (! $r->successful() || ! (str_contains($type, 'csv') || str_contains($type, 'text/plain'))) {
            throw $fail(); // prywatny plik zwraca stronę logowania (HTML)
        }
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $r->body());

        return $kind === 'document' ? array_map(fn ($line) => [$line], explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $body))) : $this->csvRows((string) $body, ',');
    }
}
