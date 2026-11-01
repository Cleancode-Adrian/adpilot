<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Kampanie-zwycięzcy ograniczane budżetem – szansa na skalowanie. */
class ScaleWinner extends Rule
{
    public function key(): string { return 'scale_winner'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $targetRoas = $ctx->targetRoas();
        foreach ($ctx->campaigns(14) as $c) {
            if ($c['conversions'] < 5 || $c['roas'] === null) {
                continue;
            }
            $goodRoas = $targetRoas ? $c['roas'] >= 1.3 * $targetRoas : false;
            $goodCpa = ! $targetRoas && ($t = $ctx->targetCpa($c['platform'])) && $c['cpa'] <= 0.7 * $t;
            if (! $goodRoas && ! $goodCpa) {
                continue;
            }
            $limited = $c['platform'] === 'google_ads' ? ($c['budget_lost_is'] ?? 0) >= 0.1 : true;
            if (! $limited) {
                continue;
            }
            $lost = $c['budget_lost_is'] ? ' Kampania traci '.round($c['budget_lost_is'] * 100).'% wyświetleń z powodu budżetu.' : '';
            yield $this->finding([
                'platform' => $c['platform'],
                'severity' => 'medium',
                'kind' => 'opportunity',
                'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                'title' => "Skaluj „{$c['name']}” – ROAS ".round($c['roas'] * 100).'%',
                'description' => 'Konwersje: '.$c['conversions'].', CPA '.$ctx->money($c['cpa']).', przychód '.$ctx->money($c['conversion_value']).' (14 dni).'.$lost,
                'action' => 'Zwiększ budżet o 15–20% (nie więcej jednorazowo, by nie resetować nauki algorytmu). Powtórz po 5–7 dniach, jeśli ROAS się utrzyma.',
                'impact' => 'Potencjalnie +'.$ctx->money($c['conversion_value'] / 14 * 30 * 0.15).' przychodu / mies.',
            ]);
        }
    }
}
