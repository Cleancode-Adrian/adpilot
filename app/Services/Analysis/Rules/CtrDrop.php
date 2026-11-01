<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Spadek CTR (7 dni vs 7 poprzednich) – kampanie Google. */
class CtrDrop extends Rule
{
    public function key(): string { return 'ctr_drop'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $prev = $ctx->campaigns(7, 7)->keyBy('id');
        foreach ($ctx->campaigns(7) as $c) {
            $p = $prev->get($c['id']);
            if (! $p || $c['impressions'] < 1000 || $p['ctr'] <= 0) {
                continue;
            }
            $drop = ($p['ctr'] - $c['ctr']) / $p['ctr'];
            if ($drop >= 0.25) {
                yield $this->finding([
                    'platform' => $c['platform'],
                    'severity' => 'medium',
                    'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                    'title' => "CTR spadł o ".round($drop * 100)."% w „{$c['name']}”",
                    'description' => "CTR {$c['ctr']}% (7 dni) vs {$p['ctr']}% tydzień wcześniej.",
                    'action' => 'Sprawdź aukcje (nowa konkurencja?), odśwież nagłówki RSA i rozszerzenia, w Meta – wymień kreacje. Sprawdź, czy nie zmieniło się kierowanie lub dopasowania słów.',
                ]);
            }
        }
    }
}
