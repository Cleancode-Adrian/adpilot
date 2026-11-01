<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Reklamy, które przepalają budżet na tle swojej kampanii. */
class AdUnderperformer extends Rule
{
    public function key(): string { return 'ad_underperformer'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $campaigns = $ctx->campaigns(14)->keyBy('id');
        foreach ($ctx->ads(14) as $ad) {
            $c = $campaigns->get($ad['campaign_id']);
            if (! $c || $ad['cost'] < 100 || $c['conversions'] < 3) {
                continue;
            }
            $share = $c['cost'] > 0 ? $ad['cost'] / $c['cost'] : 0;
            $noConv = $ad['conversions'] < 0.5 && $ad['cost'] >= max(100, ($c['cpa'] ?? 0) * 1.5);
            $badCpa = $ad['cpa'] && $c['cpa'] && $ad['cpa'] > 2 * $c['cpa'];
            if (! $noConv && ! $badCpa) {
                continue;
            }
            yield $this->finding([
                'platform' => $ad['platform'],
                'severity' => $share >= 0.2 ? 'high' : 'medium',
                'entity_type' => 'ad', 'entity_id' => $ad['id'], 'entity_name' => $ad['name'],
                'title' => "Słaba reklama: „{$ad['name']}”",
                'description' => ($noConv
                    ? 'Koszt '.$ctx->money($ad['cost']).' bez konwersji'
                    : 'CPA '.$ctx->money($ad['cpa']).' vs '.$ctx->money($c['cpa']).' średnio w kampanii')
                    ." (kampania „{$c['name']}”, 14 dni, ".round($share * 100).'% budżetu kampanii). CTR '.$ad['ctr'].'%.',
                'action' => $ad['platform'] === 'meta_ads'
                    ? 'Wyłącz reklamę i przenieś budżet na najlepsze kreacje w zestawie. Przygotuj nowy wariant (inny hook w pierwszych 3 s / inne zdjęcie główne).'
                    : 'Wstrzymaj reklamę lub przepisz nagłówki/teksty na wzór najlepszej reklamy w grupie. Sprawdź siłę reklamy RSA (cel: „Dobra” lub „Doskonała”).',
                'impact' => 'Ok. '.$ctx->money($ad['cost'] / 14 * 30).' / mies. do przesunięcia',
            ]);
        }
    }
}
