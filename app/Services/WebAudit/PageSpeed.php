<?php

namespace App\Services\WebAudit;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google PageSpeed Insights (Lighthouse) – wyniki, metryki i konkretne możliwości poprawy, po polsku.
 * Oprócz wydajności pobiera ocenę dostępności, dobrych praktyk i SEO (to, czego nie da się sprawdzić bez przeglądarki,
 * np. kontrast tekstu czy błędy w konsoli).
 */
class PageSpeed
{
    private const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function run(string $url, string $strategy = 'mobile'): ?array
    {
        if (! config('adpilot.pagespeed.enabled')) {
            return null;
        }
        // Google wymaga powtórzonego parametru category=…, więc składamy zapytanie ręcznie
        $query = http_build_query(array_filter(['url' => $url, 'strategy' => $strategy, 'locale' => 'pl', 'key' => config('adpilot.pagespeed.api_key')]))
            .'&category=performance&category=accessibility&category=best-practices&category=seo';
        try {
            $res = Http::timeout(120)->get(self::API.'?'.$query);
            if (! $res->successful() || ! is_array($res->json('lighthouseResult'))) {
                return null;
            }

            return self::parse($res->json('lighthouseResult')) + ['strategy' => $strategy];
        } catch (Throwable) {
            return null;
        }
    }

    public static function parse(array $lh): array
    {
        $a = $lh['audits'] ?? [];
        $num = fn (string $id) => $a[$id]['numericValue'] ?? null;
        $score = fn (string $cat) => isset($lh['categories'][$cat]['score']) ? (int) round($lh['categories'][$cat]['score'] * 100) : null;

        // Możliwości poprawy wydajności (oszczędność czasu lub danych)
        $perfRefs = array_column($lh['categories']['performance']['auditRefs'] ?? [], 'id');
        $opportunities = [];
        foreach ($a as $id => $audit) {
            if (($perfRefs && ! in_array($id, $perfRefs, true)) || ! isset($audit['score']) || $audit['score'] === null || $audit['score'] >= 0.9) {
                continue;
            }
            $ms = (int) round($audit['details']['overallSavingsMs'] ?? max($audit['metricSavings']['LCP'] ?? 0, $audit['metricSavings']['FCP'] ?? 0));
            $bytes = (int) ($audit['details']['overallSavingsBytes'] ?? 0);
            if ($ms < 150 && $bytes < 40 * 1024 && ! in_array($id, ['dom-size', 'dom-size-insight', 'third-party-summary', 'third-parties-insight', 'bootup-time', 'mainthread-work-breakdown', 'lcp-lazy-loaded', 'unsized-images', 'font-display', 'font-display-insight', 'uses-long-cache-ttl', 'cache-insight'], true)) {
                continue;
            }
            $opportunities[] = [
                'id' => $id,
                'title' => self::clean($audit['title'] ?? $id),
                'description' => self::clean($audit['description'] ?? ''),
                'display' => self::clean($audit['displayValue'] ?? ''),
                'savings_ms' => $ms,
                'savings_kb' => (int) round($bytes / 1024),
                'items' => self::items($audit['details']['items'] ?? []),
            ];
        }
        usort($opportunities, fn ($x, $y) => [$y['savings_ms'], $y['savings_kb']] <=> [$x['savings_ms'], $x['savings_kb']]);

        return [
            'performance' => $score('performance') ?? 0,
            'accessibility' => $score('accessibility'),
            'best_practices' => $score('best-practices'),
            'seo' => $score('seo'),
            'fcp_s' => $num('first-contentful-paint') !== null ? round($num('first-contentful-paint') / 1000, 1) : null,
            'lcp_s' => round(($num('largest-contentful-paint') ?? 0) / 1000, 1),
            'si_s' => $num('speed-index') !== null ? round($num('speed-index') / 1000, 1) : null,
            'tbt_ms' => (int) ($num('total-blocking-time') ?? 0),
            'cls' => round($num('cumulative-layout-shift') ?? 0, 3),
            'ttfb_ms' => $num('server-response-time') !== null ? (int) $num('server-response-time') : null,
            'total_kb' => $num('total-byte-weight') !== null ? (int) round($num('total-byte-weight') / 1024) : null,
            'requests' => isset($a['network-requests']['details']['items']) ? count($a['network-requests']['details']['items']) : null,
            'dom' => $num('dom-size') !== null ? (int) $num('dom-size') : null,
            'lcp_element' => self::clean($a['largest-contentful-paint-element']['details']['items'][0]['node']['nodeLabel']
                ?? $a['largest-contentful-paint-element']['details']['items'][0]['items'][0]['node']['nodeLabel'] ?? ''),
            'opportunities' => array_slice($opportunities, 0, 12),
            'accessibility_fails' => self::fails($lh, $a, 'accessibility'),
            'best_practices_fails' => self::fails($lh, $a, 'best-practices'),
        ];
    }

    /** Niezaliczone sprawdzenia kategorii (np. kontrast, nazwy przycisków, błędy w konsoli). */
    private static function fails(array $lh, array $a, string $cat): array
    {
        $out = [];
        foreach ($lh['categories'][$cat]['auditRefs'] ?? [] as $ref) {
            $audit = $a[$ref['id']] ?? null;
            if (! $audit || ($ref['weight'] ?? 0) <= 0 || ($audit['score'] ?? 1) === null || ($audit['score'] ?? 1) >= 0.9) {
                continue;
            }
            $items = $audit['details']['items'] ?? [];
            $out[] = [
                'id' => $ref['id'],
                'title' => self::clean($audit['title'] ?? $ref['id']),
                'description' => self::clean($audit['description'] ?? ''),
                'count' => count($items),
                'examples' => array_values(array_filter(array_map(fn ($i) => self::clean($i['node']['nodeLabel'] ?? $i['node']['snippet'] ?? $i['description'] ?? $i['source']['url'] ?? ''), array_slice($items, 0, 4)))),
                'weight' => $ref['weight'],
            ];
        }
        usort($out, fn ($x, $y) => $y['weight'] <=> $x['weight']);

        return array_slice($out, 0, 10);
    }

    private static function items(array $items): array
    {
        $out = [];
        foreach (array_slice($items, 0, 5) as $i) {
            $url = $i['url'] ?? $i['source']['url'] ?? ($i['node']['nodeLabel'] ?? null) ?? ($i['entity'] ?? null);
            if (is_array($url)) {
                $url = $url['text'] ?? null;
            }
            if (! $url) {
                continue;
            }
            $out[] = array_filter([
                'url' => mb_substr((string) $url, 0, 200),
                'kb' => isset($i['totalBytes']) ? (int) round($i['totalBytes'] / 1024) : (isset($i['transferSize']) ? (int) round($i['transferSize'] / 1024) : null),
                'wasted_kb' => isset($i['wastedBytes']) ? (int) round($i['wastedBytes'] / 1024) : null,
                'ms' => isset($i['wastedMs']) ? (int) $i['wastedMs'] : (isset($i['blockingTime']) ? (int) $i['blockingTime'] : null),
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** Usuwa linki Markdown („[Więcej informacji](…)”) i znaczniki z opisów Lighthouse. */
    private static function clean(string $s): string
    {
        $s = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $s);
        $s = preg_replace('/\s*(Więcej informacji|Learn more)[^.]*\.?\s*$/iu', '', $s);

        return trim(str_replace('`', '', strip_tags($s)));
    }
}
