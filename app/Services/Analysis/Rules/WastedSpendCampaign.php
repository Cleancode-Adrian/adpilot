<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Wydatki bez konwersji na poziomie kampanii (14 dni). */
class WastedSpendCampaign extends Rule
{
    public function key(): string { return 'wasted_spend_campaign'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        foreach ($ctx->campaigns(14) as $c) {
            $threshold = max(2 * ($ctx->targetCpa($c['platform']) ?? 0), 150);
            if ($c['conversions'] < 0.5 && $c['cost'] >= $threshold) {
                yield $this->finding([
                    'platform' => $c['platform'],
                    'severity' => 'critical',
                    'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                    'title' => "Kampania „{$c['name']}” wydała {$ctx->money($c['cost'])} bez konwersji",
                    'description' => "W ostatnich 14 dniach: {$c['clicks']} kliknięć, CTR {$c['ctr']}%, CPC {$ctx->money($c['cpc'])}, 0 konwersji.",
                    'action' => $c['platform'] === 'google_ads'
                        ? 'Sprawdź wyszukiwane hasła i dodaj wykluczenia, zweryfikuj stronę docelową oraz czy konwersja jest poprawnie przypisana do kampanii. Jeśli kampania jest w nauce <7 dni – obserwuj, w przeciwnym razie obniż budżet o 50% lub wstrzymaj.'
                        : 'Sprawdź, czy zdarzenie Purchase trafia do Pixela/CAPI. Zweryfikuj grupę odbiorców i kreacje; jeśli zestaw jest poza fazą uczenia – wstrzymaj najsłabsze reklamy lub cały zestaw.',
                    'impact' => 'Oszczędność do '.$ctx->money($c['cost'] / 14 * 30).' / mies.',
                    'data' => ['cost' => $c['cost'], 'clicks' => $c['clicks']],
                ]);
            }
        }
    }
}
