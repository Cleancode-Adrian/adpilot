<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** ROAS poniżej celu. */
class LowRoas extends Rule
{
    public function key(): string { return 'low_roas'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $target = $ctx->targetRoas();
        if (! $target) {
            return;
        }
        foreach ($ctx->campaigns(14) as $c) {
            if ($c['cost'] < 300 || $c['roas'] === null || $c['conversions'] < 0.5 || $c['roas'] >= 0.7 * $target) {
                continue;
            }
            yield $this->finding([
                'platform' => $c['platform'],
                'severity' => $c['roas'] < 0.4 * $target ? 'critical' : 'high',
                'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                'title' => "ROAS ".round($c['roas'] * 100)."% w „{$c['name']}” (cel ".round($target * 100).'%)',
                'description' => 'Przychód '.$ctx->money($c['conversion_value']).' przy koszcie '.$ctx->money($c['cost']).' w 14 dni.',
                'action' => $c['platform'] === 'google_ads'
                    ? 'Wyklucz produkty/grupy z wydatkami bez sprzedaży, ustaw docelowy ROAS, rozdziel bestsellery do osobnej kampanii, sprawdź konkurencyjność cen w Merchant Center.'
                    : 'Testuj nowe kreacje i oferty (darmowa dostawa, rabat na 1. zakup), wyklucz osoby, które kupiły w ostatnich 30 dniach, sprawdź odbiorców Advantage+.',
                'data' => ['roas' => $c['roas'], 'target' => $target],
            ]);
        }
    }
}
