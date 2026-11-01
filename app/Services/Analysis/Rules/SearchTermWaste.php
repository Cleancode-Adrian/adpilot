<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Hasła wyszukiwania bez konwersji – kandydaci na wykluczenia. */
class SearchTermWaste extends Rule
{
    public function key(): string { return 'search_term_waste'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $min = max(($ctx->targetCpa('google_ads') ?? 0) * 0.8, 40);
        $waste = $ctx->searchTerms()
            ->filter(fn ($t) => $t->conversions < 0.5 && $t->cost >= $min)
            ->sortByDesc('cost')->values();

        if ($waste->isEmpty()) {
            return;
        }
        $total = $waste->sum('cost');
        $list = $waste->take(15)->map(fn ($t) => [
            'term' => $t->term, 'cost' => round($t->cost, 2), 'clicks' => $t->clicks, 'campaign' => $t->campaign?->name,
        ])->all();

        yield $this->finding([
            'platform' => 'google_ads',
            'severity' => $total >= 500 ? 'high' : 'medium',
            'entity_type' => 'search_terms', 'entity_id' => 'all',
            'title' => $waste->count().' wyszukiwanych haseł bez konwersji ('.$ctx->money($total).')',
            'description' => 'Hasła z kosztem ≥ '.$ctx->money($min).' i 0 konwersji w ostatnich 30 dniach. Najdroższe: '
                .$waste->take(5)->map(fn ($t) => '„'.$t->term.'” ('.$ctx->money($t->cost).')')->implode(', ').'.',
            'action' => 'Przejrzyj listę i dodaj nietrafione hasła jako wykluczające (dopasowanie do wyrażenia lub ścisłe) na poziomie kampanii lub listy wykluczeń konta. Zostaw hasła trafne, ale z małą liczbą kliknięć.',
            'impact' => 'Oszczędność do '.$ctx->money($total).' / mies.',
            'data' => ['terms' => $list],
        ]);
    }
}
