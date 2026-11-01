<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** CPA znacząco powyżej celu. */
class HighCpa extends Rule
{
    public function key(): string { return 'high_cpa'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        foreach ($ctx->campaigns(14) as $c) {
            $target = $ctx->targetCpa($c['platform']);
            if (! $target || ! $c['cpa'] || $c['cost'] < 200 || $c['cpa'] <= 1.5 * $target) {
                continue;
            }
            // Sklep rozliczany ROAS-em i kampania mierzy wartość → ocenia ją reguła LowRoas, nie CPA
            if ($ctx->targetRoas() && $c['conversion_value'] > 0) {
                continue;
            }
            yield $this->finding([
                'platform' => $c['platform'],
                'severity' => 'high',
                'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                'title' => ($ctx->client->goal === 'leads' ? 'Wysoki koszt pozyskania leada' : 'Wysoki koszt konwersji')." w „{$c['name']}”",
                'description' => "CPA {$ctx->money($c['cpa'])} vs cel {$ctx->money($target)} (".round($c['cpa'] / $target * 100).'% celu). Koszt: '.$ctx->money($c['cost']).", konwersje: {$c['conversions']}.",
                'action' => 'Przenieś budżet do lepszych kampanii, zawęź kierowanie (lokalizacje, urządzenia, harmonogram), sprawdź najsłabsze reklamy i hasła. W Smart Bidding ustaw docelowy CPA/ROAS zamiast maks. konwersji.',
                'data' => ['cpa' => $c['cpa'], 'target' => $target],
            ]);
        }
    }
}
