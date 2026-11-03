<?php

namespace App\Services\WebAudit;

use App\Models\WebAudit;
use App\Services\SiteAudit\SiteAuditor;
use App\Services\Tracking\TrackingAuditor;
use Throwable;

/**
 * Audyt dowolnej strony w kilku dziedzinach – narzędzie sprzedażowe dla potencjalnych klientów.
 * Każda dziedzina liczy się osobno; awaria jednej (np. PageSpeed) nie psuje reszty.
 */
class WebAuditor
{
    public const AREAS = [
        'seo' => 'SEO i technika',
        'speed' => 'Szybkość',
        'tracking' => 'Pomiar i marketing',
        'security' => 'Bezpieczeństwo',
    ];

    public function __construct(
        private SiteAuditor $seo,
        private TrackingAuditor $tracking,
        private SecurityScanner $security,
    ) {}

    public function run(WebAudit $audit): void
    {
        $audit->update(['status' => 'running', 'error' => null]);
        $areas = [];

        // SEO i technika – do 30 podstron (szybciej niż pełny audyt klienta)
        try {
            $seo = $this->seo->crawl($audit->url, (int) config('adpilot.site_audit.prospect_pages', 30));
            $areas['seo'] = ['score' => $seo['score'], 'issues' => $seo['issues'], 'summary' => $seo['summary'], 'pages' => count($seo['pages'])];
        } catch (Throwable $e) {
            $areas['seo'] = ['score' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        // Pomiar i marketing (GA4, GTM, piksel, zgody) + PageSpeed z tego samego skanu
        $pagespeed = null;
        try {
            $t = $this->tracking->audit($audit->url);
            $pagespeed = $t['pagespeed'] ?? null;
            $areas['tracking'] = ['score' => $t['score'], 'checks' => array_values(array_filter($t['checks'], fn ($c) => $c['group'] !== 'Szybkość')),
                'platform' => $t['platform'], 'detected' => $t['detected']];
        } catch (Throwable $e) {
            $areas['tracking'] = ['score' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        // Szybkość: Google PageSpeed (telefon) + czas odpowiedzi serwera z przejścia po stronie
        $avgMs = $areas['seo']['summary']['avg_ms'] ?? null;
        $areas['speed'] = [
            'score' => $pagespeed['performance'] ?? ($avgMs ? max(0, min(100, (int) round(100 - ($avgMs - 400) / 30))) : null),
            'pagespeed' => $pagespeed,
            'avg_ms' => $avgMs,
            'source' => $pagespeed ? 'pagespeed' : ($avgMs ? 'response' : null),
        ];

        try {
            $areas['security'] = $this->security->scan($audit->url);
        } catch (Throwable $e) {
            $areas['security'] = ['score' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        $scores = array_filter(array_column($areas, 'score'), fn ($s) => $s !== null);
        if (! $scores) {
            $audit->update(['status' => 'failed', 'areas' => $areas, 'finished_at' => now(),
                'error' => $areas['seo']['error'] ?? $areas['tracking']['error'] ?? 'Strona nie odpowiada.']);

            return;
        }
        $audit->update(['status' => 'done', 'areas' => $areas, 'score' => (int) round(array_sum($scores) / count($scores)), 'finished_at' => now()]);
    }
}
