<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Zmęczenie kreacji w Meta: rosnąca częstotliwość, spadający CTR. */
class CreativeFatigue extends Rule
{
    public function key(): string { return 'creative_fatigue'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $prev = $ctx->ads(7, 7)->keyBy('id');
        foreach ($ctx->ads(7)->where('platform', 'meta_ads') as $ad) {
            $p = $prev->get($ad['id']);
            if (! $p || $ad['impressions'] < 2000 || ($ad['frequency'] ?? 0) < 2.5 || $p['ctr'] <= 0) {
                continue;
            }
            $drop = ($p['ctr'] - $ad['ctr']) / $p['ctr'];
            if ($drop < 0.2) {
                continue;
            }
            yield $this->finding([
                'platform' => 'meta_ads',
                'severity' => ($ad['frequency'] ?? 0) >= 4 ? 'high' : 'medium',
                'entity_type' => 'ad', 'entity_id' => $ad['id'], 'entity_name' => $ad['name'],
                'title' => "Wypalenie kreacji: „{$ad['name']}”",
                'description' => 'Częstotliwość '.round($ad['frequency'], 1)." i spadek CTR o ".round($drop * 100)."% tydzień do tygodnia ({$p['ctr']}% → {$ad['ctr']}%).",
                'action' => 'Wymień kreację na nową (nowy hook, format wideo/UGC) lub poszerz grupę odbiorców. Przy remarketingu – skróć okno lub wyklucz kupujących.',
            ]);
        }
    }
}
