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
        'speed' => 'Szybkość i optymalizacja',
        'tracking' => 'Pomiar i marketing',
        'security' => 'Bezpieczeństwo',
    ];

    public function __construct(
        private SiteAuditor $seo,
        private TrackingAuditor $tracking,
        private SecurityScanner $security,
        private PageInspector $inspector,
        private AiPageReviewer $reviewer,
        private AuditReportBuilder $builder,
        private PageSpeed $pagespeedClient,
    ) {}

    public function run(WebAudit $audit): void
    {
        $audit->update(['status' => 'running', 'error' => null]);
        $areas = [];
        $blocked = null; // nazwa zabezpieczenia, jeśli serwer pokazał ekran weryfikacji zamiast strony
        $fail = function (Throwable $e) use (&$blocked): array {
            if ($e instanceof SiteBlockedException) {
                $blocked ??= $e->protection;

                return ['score' => null, 'error' => 'Serwer pokazał ekran weryfikacji zamiast strony ('.$e->protection.') – tej części nie oceniamy.', 'blocked' => $e->protection];
            }

            return ['score' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        };

        // 1. Najważniejsze podstrony – najpierw, zanim przejście po stronie zbliży się do limitu zapytań hostingu
        $inspection = $inspectionError = null;
        try {
            $inspection = $this->inspector->inspect($audit->url, (array) ($audit->options['pages'] ?? []));
        } catch (Throwable $e) {
            $inspectionError = $fail($e)['error'];
        }

        // 2. SEO i technika – do 30 podstron (spokojnie, paczkami)
        try {
            $seo = $this->seo->crawl($audit->url, (int) config('adpilot.site_audit.prospect_pages', 30));
            $areas['seo'] = ['score' => $seo['score'], 'issues' => $seo['issues'], 'summary' => $seo['summary'], 'pages' => count($seo['pages'])];
            $blocked ??= $seo['summary']['blocked'] ?? null;
        } catch (Throwable $e) {
            $areas['seo'] = $fail($e);
        }

        // 3. Pomiar i marketing (GA4, GTM, piksel, zgody) + PageSpeed z tego samego skanu
        $pagespeed = null;
        try {
            $t = $this->tracking->audit($audit->url);
            $pagespeed = $t['pagespeed'] ?? null;
            $areas['tracking'] = ['score' => $t['score'], 'checks' => array_values(array_filter($t['checks'], fn ($c) => $c['group'] !== 'Szybkość')),
                'platform' => $t['platform'], 'detected' => $t['detected']];
        } catch (Throwable $e) {
            $areas['tracking'] = $fail($e);
            // Google PageSpeed używa prawdziwej przeglądarki – zwykle działa nawet wtedy, gdy nasz skaner jest blokowany
            $pagespeed = $this->pagespeedClient->run($audit->url, 'mobile');
        }

        // 4. Szybkość: Google PageSpeed (telefon i komputer) + czas odpowiedzi serwera z przejścia po stronie
        $avgMs = $areas['seo']['summary']['avg_ms'] ?? null;
        $areas['speed'] = [
            'score' => $pagespeed['performance'] ?? ($avgMs ? max(0, min(100, (int) round(100 - ($avgMs - 400) / 30))) : null),
            'pagespeed' => $pagespeed,
            'desktop' => $pagespeed ? $this->pagespeedClient->run($audit->url, 'desktop') : null,
            'avg_ms' => $avgMs,
            'source' => $pagespeed ? 'pagespeed' : ($avgMs ? 'response' : null),
        ];

        // 5. Bezpieczeństwo
        try {
            $areas['security'] = $this->security->scan($audit->url);
        } catch (Throwable $e) {
            $areas['security'] = $fail($e);
        }

        $scores = array_filter(array_column($areas, 'score'), fn ($s) => $s !== null);
        // Strona główna nie działa i nie udało się pobrać żadnej podstrony – nie wystawiamy oceny stronie błędu
        $dead = ! $inspection && ! $blocked && (int) ($areas['seo']['summary']['html_pages'] ?? 0) === 0;
        if (! $scores || $dead) {
            $audit->update(['status' => 'failed', 'areas' => $areas, 'finished_at' => now(),
                'error' => match (true) {
                    (bool) $blocked => 'Serwer strony pokazuje naszemu skanerowi ekran weryfikacji ('.$blocked.') zamiast treści – to zabezpieczenie hostingu przed automatami, nie błąd widoczny dla klientów. Spróbuj ponownie za kilkanaście minut albo poproś o dodanie adresu IP serwera AdPilot do wyjątków zabezpieczenia.',
                    $dead => 'Strona nie działa – '.lcfirst(rtrim((string) $inspectionError, '.')).'. Sprawdź adres albo ponów audyt, gdy strona wróci.',
                    default => $areas['seo']['error'] ?? $areas['tracking']['error'] ?? 'Strona nie odpowiada.',
                }]);

            return;
        }

        // 6. Przegląd treści przez AI – tylko na prawdziwej treści podstron
        $ai = null;
        if ($inspection && ($audit->options['ai'] ?? true)) { // w audycie zbiorczym przegląd AI można wyłączyć
            try {
                $ai = $this->reviewer->review($inspection, $inspection['findings'], $audit->name);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $report = $this->builder->build($audit, $areas, $inspection, $ai, $inspectionError, $blocked);

        $audit->update(['status' => 'done', 'areas' => $areas, 'report' => $report, 'score' => (int) round(array_sum($scores) / count($scores)), 'finished_at' => now()]);
    }
}
