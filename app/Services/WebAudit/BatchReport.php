<?php

namespace App\Services\WebAudit;

use App\Models\AuditBatch;
use App\Models\AuditBatchItem;
use App\Models\WebAudit;
use Illuminate\Support\Collection;

/**
 * Zestawienia audytu zbiorczego: przegląd w panelu (ze skrótów przy pozycjach – bez ładowania pełnych raportów),
 * najczęstsze problemy na wielu stronach, raport zbiorczy do udostępnienia i arkusz wszystkich uwag.
 */
class BatchReport
{
    private const SEV_ORDER = ['pilne' => 0, 'srednie' => 1, 'drobne' => 2];

    /** Pozycje listy do panelu: stan, ocena, liczby uwag i kilka najpilniejszych spraw. */
    public function items(Collection $items): array
    {
        return $items->map(function (AuditBatchItem $i) {
            $s = $i->summary ?? [];
            $done = ($s['status'] ?? null) === 'done' && $i->state() === 'done';

            return [
                'id' => $i->id,
                'label' => $i->label,
                'url' => $i->url,
                'pages' => $i->pages ?? [],
                'note' => $i->note,
                'enabled' => $i->enabled,
                'state' => $i->state(),
                'audit_id' => $i->web_audit_id,
                'score' => $done ? $s['score'] : null,
                'areas' => $done ? ($s['areas'] ?? []) : [],
                'counts' => $done ? $s['counts'] : null,
                'verify' => $done ? ($s['verify'] ?? 0) : 0,
                'company' => $done ? ($s['company'] ?? null) : null,
                'blocked' => $s['blocked'] ?? null,
                'error' => $i->state() === 'failed' ? ($s['error'] ?? $i->audit?->error) : null,
                'top' => $done ? array_values(array_map(fn ($f) => $f['t'], array_slice(array_filter($s['findings'] ?? [], fn ($f) => $f['s'] === 'pilne'), 0, 6))) : [],
            ];
        })->values()->all();
    }

    /** Wyniki łącznie: średnia ocena, suma uwag, średnie z dziedzin. */
    public function stats(Collection $items): array
    {
        $done = $items->filter(fn (AuditBatchItem $i) => ($i->summary['status'] ?? null) === 'done' && $i->state() === 'done');
        $counts = ['pilne' => 0, 'srednie' => 0, 'drobne' => 0];
        foreach ($done as $i) {
            foreach ($counts as $k => $v) {
                $counts[$k] += (int) ($i->summary['counts'][$k] ?? 0);
            }
        }
        $areas = [];
        foreach (array_keys(WebAuditor::AREAS) as $k) {
            $vals = $done->map(fn ($i) => $i->summary['areas'][$k] ?? null)->filter(fn ($v) => $v !== null);
            $areas[$k] = $vals->count() ? (int) round($vals->avg()) : null;
        }

        return [
            'sites' => $done->count(),
            'avg_score' => $done->count() ? (int) round($done->avg(fn ($i) => $i->summary['score'])) : null,
            'counts' => $counts,
            'areas' => $areas,
            'verify' => (int) $done->sum(fn ($i) => $i->summary['verify'] ?? 0),
        ];
    }

    /**
     * Te same problemy na wielu stronach (np. „brak polityki prywatności” na 12 z 60) – od najważniejszych i najczęstszych.
     * Uwagi z przeglądu AI są pisane dla konkretnej strony, więc nie wchodzą do zestawienia.
     *
     * @return list<array{key: string, title: string, sev: string, section: string, count: int, sites: list<string>}>
     */
    public function common(Collection $items, int $min = 2, int $limit = 60): array
    {
        $groups = [];
        foreach ($items as $i) {
            if (($i->summary['status'] ?? null) !== 'done') {
                continue;
            }
            $seen = [];
            foreach ($i->summary['findings'] ?? [] as $f) {
                if (! empty($f['ai']) || $f['k'] === '' || isset($seen[$f['k']])) {
                    continue;
                }
                $seen[$f['k']] = true;
                $g = &$groups[$f['k']];
                $g ??= ['key' => $f['k'], 'titles' => [], 'sevs' => [], 'section' => $f['w'], 'sites' => []];
                $g['titles'][$f['t']] = ($g['titles'][$f['t']] ?? 0) + 1;
                $g['sevs'][$f['s']] = ($g['sevs'][$f['s']] ?? 0) + 1;
                $g['sites'][] = $i->label;
                unset($g);
            }
        }
        $out = [];
        foreach ($groups as $g) {
            if (count($g['sites']) < $min) {
                continue;
            }
            arsort($g['titles']);
            arsort($g['sevs']);
            $out[] = ['key' => $g['key'], 'title' => $this->generalTitle((string) array_key_first($g['titles'])), 'sev' => (string) array_key_first($g['sevs']), 'section' => $g['section'],
                'count' => count($g['sites']), 'sites' => $g['sites']];
        }
        usort($out, fn ($a, $b) => [self::SEV_ORDER[$a['sev']], -$a['count']] <=> [self::SEV_ORDER[$b['sev']], -$b['count']]);

        return array_slice($out, 0, $limit);
    }

    /**
     * Porównanie z poprzednim audytem tej samej listy: zmiana ocen, problemy, które zniknęły, i te, które doszły.
     * Strony łączone po nazwie z listy; uwagi – po kluczu problemu (bez uwag z przeglądu AI, które za każdym razem brzmią inaczej).
     */
    public function compare(Collection $items, AuditBatch $previous): array
    {
        $before = $previous->items()->get()->keyBy('label');
        $keys = fn (?AuditBatchItem $i) => collect($i?->summary['findings'] ?? [])->reject(fn ($f) => ! empty($f['ai']) || $f['k'] === '')->keyBy('k');
        $sites = [];
        $fixed = $new = [];
        foreach ($items as $item) {
            $old = $before[$item->label] ?? null;
            if (($item->summary['status'] ?? null) !== 'done' || ($old?->summary['status'] ?? null) !== 'done') {
                continue;
            }
            $now = $keys($item);
            $was = $keys($old);
            $gone = $was->diffKeys($now);
            $came = $now->diffKeys($was);
            foreach ($gone as $k => $f) {
                $fixed[$k] ??= ['title' => $this->generalTitle($f['t']), 'sev' => $f['s'], 'sites' => []];
                $fixed[$k]['sites'][] = $item->label;
            }
            foreach ($came as $k => $f) {
                $new[$k] ??= ['title' => $this->generalTitle($f['t']), 'sev' => $f['s'], 'sites' => []];
                $new[$k]['sites'][] = $item->label;
            }
            $sites[$item->label] = [
                'before' => $old->summary['score'], 'after' => $item->summary['score'],
                'pilne_before' => (int) ($old->summary['counts']['pilne'] ?? 0), 'pilne_after' => (int) ($item->summary['counts']['pilne'] ?? 0),
                'fixed' => $gone->count(), 'new' => $came->count(),
            ];
        }
        $rank = function (array $groups) {
            $groups = array_values(array_map(fn ($g) => $g + ['count' => count($g['sites'])], $groups));
            usort($groups, fn ($a, $b) => [self::SEV_ORDER[$a['sev']], -$a['count']] <=> [self::SEV_ORDER[$b['sev']], -$b['count']]);

            return array_slice($groups, 0, 40);
        };
        $s = collect($sites);

        return [
            'previous' => $previous->only(['id', 'name', 'finished_at']),
            'sites_compared' => $s->count(),
            'avg_before' => $s->count() ? (int) round($s->avg('before')) : null,
            'avg_after' => $s->count() ? (int) round($s->avg('after')) : null,
            'pilne_before' => (int) $s->sum('pilne_before'),
            'pilne_after' => (int) $s->sum('pilne_after'),
            'improved' => $s->filter(fn ($x) => $x['after'] > $x['before'])->count(),
            'worse' => $s->filter(fn ($x) => $x['after'] < $x['before'])->count(),
            'fixed_total' => (int) $s->sum('fixed'),
            'new_total' => (int) $s->sum('new'),
            'fixed' => $rank($fixed),
            'new' => $rank($new),
            'sites' => $sites,
        ];
    }

    /** Tytuł problemu bez szczegółów jednej strony: „Ciężkie zdjęcia (14 plików powyżej 250 KB)” → „Ciężkie zdjęcia”. */
    private function generalTitle(string $title): string
    {
        $t = preg_replace('/\s*\([^)]*\d[^)]*\)/u', '', $title);                       // dopiski z liczbami w nawiasach
        $t = preg_replace('/\s+[–-]\s+\d+\s+z\s+\d+\s+.*$/u', '', (string) $t);        // „– 29 z 30 sprawdzonych podstron”

        return trim((string) $t) ?: $title;
    }

    /**
     * Raport zbiorczy: każda strona z oceną, pilnymi sprawami (z opisem) i średnimi (tytuł + co zrobić).
     * Pełne raporty ładowane są pojedynczo – każdy to kilkaset KB.
     */
    public function sites(AuditBatch $batch): array
    {
        $out = [];
        foreach ($batch->items()->where('enabled', true)->get() as $item) {
            $audit = $item->web_audit_id ? WebAudit::find($item->web_audit_id) : null;
            $site = ['label' => $item->label, 'url' => $item->url, 'note' => $item->note, 'state' => $audit?->status ?? 'waiting', 'score' => null, 'areas' => [], 'counts' => null,
                'company' => null, 'summary' => null, 'error' => null, 'public_url' => null, 'pdf_url' => null, 'pilne' => [], 'srednie' => [], 'pages' => []];
            if ($audit && $audit->status === 'done') {
                $r = $audit->report ?? [];
                $site = array_merge($site, [
                    'score' => $audit->score,
                    'areas' => collect($audit->areas ?? [])->map(fn ($a) => $a['score'] ?? null)->all(),
                    'counts' => $r['counts'] ?? null,
                    'company' => $r['company'] ?? null,
                    'summary' => $r['summary'] ?? null,
                    'pages' => array_map(fn ($p) => ['label' => $p['label'], 'url' => $p['url'], 'ok' => $p['ok']], $r['pages'] ?? []),
                    'public_url' => route('public.web-audit', $audit->token),
                    'pdf_url' => route('public.web-audit.pdf', $audit->token),
                ]);
                foreach ($this->findings($r) as $f) {
                    if ($f['sev'] === 'pilne') {
                        $site['pilne'][] = $f;
                    } elseif ($f['sev'] === 'srednie') {
                        $site['srednie'][] = $f;
                    }
                }
            } elseif ($audit && $audit->status === 'failed') {
                $site['error'] = $audit->error;
            }
            $out[] = $site;
        }

        return $out;
    }

    /**
     * Wszystkie uwagi ze wszystkich stron – wiersze arkusza (Excel).
     *
     * @return \Generator<int, list<string|int|null>>
     */
    public function rows(AuditBatch $batch): \Generator
    {
        yield ['Lp.', 'Strona', 'Adres', 'Ocena', 'Ważność', 'Nr', 'Część raportu', 'Podstrona', 'Grupa', 'Uwaga', 'Opis', 'Co zrobić', 'Do potwierdzenia', 'Przykład z kodu / treści', 'Pełny raport'];
        $n = 0;
        foreach ($batch->items()->where('enabled', true)->get() as $item) {
            $n++;
            $audit = $item->web_audit_id ? WebAudit::find($item->web_audit_id) : null;
            if (! $audit || $audit->status !== 'done') {
                yield [$n, $item->label, $item->url, null, null, null, null, null, null,
                    $audit?->status === 'failed' ? 'Audyt nieudany' : 'Audyt niewykonany', $audit?->error ?: $item->note, null, null, null, null];

                continue;
            }
            $link = route('public.web-audit', $audit->token);
            foreach ($this->findings($audit->report ?? []) as $f) {
                yield [$n, $item->label, $item->url, $audit->score, AuditReportBuilder::SEV[$f['sev']] ?? $f['sev'], $f['id'], $f['section'], $f['page_url'], $f['group'],
                    $f['title'], $f['text'], $f['fix'], $f['verify'] ? 'tak' : '', $f['evidence'], $link];
            }
        }
    }

    /** Uwagi z raportu jednej strony, spłaszczone: od najpilniejszych, z nazwą części raportu i adresem podstrony. */
    private function findings(array $report): array
    {
        $pageUrls = collect($report['pages'] ?? [])->pluck('url', 'role');
        $out = [];
        foreach ($report['sections'] ?? [] as $s) {
            foreach ($s['groups'] as $g) {
                foreach ($g['findings'] as $f) {
                    $out[] = ['id' => $f['id'], 'sev' => $f['sev'], 'section' => $s['title'], 'page_url' => $s['url'] ?? ($pageUrls[$f['page']] ?? null), 'group' => $g['title'],
                        'title' => $f['title'], 'text' => $f['text'], 'fix' => $f['fix'], 'verify' => (bool) $f['verify'], 'evidence' => $f['evidence']];
                }
            }
        }
        usort($out, fn ($a, $b) => self::SEV_ORDER[$a['sev']] <=> self::SEV_ORDER[$b['sev']]);

        return $out;
    }
}
