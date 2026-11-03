<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/**
 * Lejek Meta: gdzie ginie ruch między kliknięciem w reklamę a stroną.
 * 1) kliknięcia w link bez wejść na stronę (LPV) – kampania po kampanii,
 * 2) umiejscowienia z kliknięciami bez wejść (Audience Network, „wideo z nagrodą”),
 * 3) Meta widzi wejścia, GA4 nie widzi sesji z Meta (UTM-y, tag, zgody).
 */
class MetaFunnelLeaks extends Rule
{
    public function key(): string { return 'meta_funnel'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        // 1) Kampanie: kliknięcia w link → wejścia na stronę
        foreach ($ctx->campaigns(14)->where('platform', 'meta_ads') as $c) {
            $link = (int) ($c['link_clicks'] ?? 0);
            $lpv = (int) ($c['landing_page_views'] ?? 0);
            // formularz Meta, Messenger, WhatsApp… – nikt nie przechodzi na stronę, brak wejść jest normalny
            if ($link < 10 || in_array($c['destination'] ?? null, \App\Models\Campaign::OFF_SITE_DESTINATIONS, true)) {
                continue;
            }
            $rate = $lpv / $link;
            if ($rate >= 0.5) {
                continue;
            }
            yield $this->finding([
                'platform' => 'meta_ads',
                'severity' => $rate < 0.25 ? 'critical' : 'high',
                'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                'title' => "Ruch z reklam nie dociera na stronę: „{$c['name']}”",
                'description' => "Z {$link} kliknięć w link tylko {$lpv} skończyło się wejściem na stronę (".round($rate * 100)."%, norma 60–80%). Płacisz za kliknięcia, które nie przynoszą odwiedzin.",
                'action' => 'Zobacz tabelę umiejscowień w Wynikach reklam. Najczęstsza przyczyna: Audience Network (przypadkowe kliknięcia w aplikacjach i grach) – wyklucz je. Sprawdź też szybkość strony na telefonie i czy link nie przekierowuje.',
                'impact' => round((float) $c['cost'] * (1 - $rate), 2),
            ]);
        }

        // 2) Umiejscowienia z kliknięciami bez wejść
        $placements = $ctx->placements(14)->where('suspicious', true);
        $an = $placements->where('platform', 'audience_network');
        if ($an->isNotEmpty()) {
            $clicks = $an->sum('link_clicks');
            $all = max(1, $ctx->placements(14)->sum('link_clicks'));
            yield $this->finding([
                'platform' => 'meta_ads',
                'severity' => 'high',
                'entity_type' => 'placement', 'entity_id' => 'audience_network',
                'title' => 'Wyklucz Audience Network – kliknięcia bez wejść na stronę',
                'description' => "Audience Network dało {$clicks} kliknięć w link (".round($clicks / $all * 100).'% wszystkich) i tylko '.$an->sum('landing_page_views').' wejść na stronę: '
                    .$an->pluck('name')->implode(', ').'. To zwykle przypadkowe kliknięcia albo kliknięcia „za nagrodę” w grach.',
                'action' => 'Menedżer reklam → zestaw reklam → Umiejscowienia → Ręczne → odznacz Audience Network. Dla całego konta: Ustawienia konta reklamowego → Kontrola umiejscowień → wyklucz Audience Network.',
                'impact' => round($an->sum('cost'), 2),
            ]);
        }
        foreach ($placements->where('platform', '!=', 'audience_network') as $p) {
            yield $this->finding([
                'platform' => 'meta_ads',
                'severity' => 'medium',
                'entity_type' => 'placement', 'entity_id' => $p['platform'].':'.$p['position'],
                'title' => "Podejrzane kliknięcia: {$p['name']}",
                'description' => "{$p['link_clicks']} kliknięć w link (CTR {$p['link_ctr']}%) i {$p['landing_page_views']} wejść na stronę w 14 dni.",
                'action' => 'Jeśli sytuacja się utrzymuje – wyklucz to umiejscowienie w zestawie reklam (Umiejscowienia → Ręczne).',
                'impact' => $p['cost'],
            ]);
        }

        // 3) Meta vs GA4: wejścia wg Meta a sesje z Meta w GA4
        $f = $ctx->funnel(14);
        $steps = $f ? collect($f['steps'])->keyBy('key') : null;
        if ($steps && $steps->has('ga_sessions') && $steps['lpv']['value'] >= 15 && ($steps['ga_sessions']['rate'] ?? 100) < 30) {
            yield $this->finding([
                'platform' => 'tracking',
                'severity' => 'high',
                'entity_type' => 'tracking', 'entity_id' => 'meta_vs_ga4_sessions',
                'title' => 'GA4 nie widzi większości wejść z reklam Meta',
                'description' => "Meta: {$steps['lpv']['value']} wejść na stronę, GA4: {$steps['ga_sessions']['value']} sesji ze źródeł Meta ({$steps['ga_sessions']['rate']}%) w 14 dni.",
                'action' => 'Sprawdź: 1) parametry UTM w linku reklamy (utm_source=facebook, utm_medium=paid), 2) czy tag GA4 działa na stronie docelowej, 3) odsetek zgód w banerze cookies – przy odmowie GA4 nie liczy sesji (Consent Mode).',
            ]);
        }
    }
}
