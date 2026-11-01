<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Najlepsze reklamy – warto je powielić / dać im więcej budżetu. */
class AdTopPerformer extends Rule
{
    public function key(): string { return 'ad_top_performer'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $campaigns = $ctx->campaigns(14)->keyBy('id');
        $found = 0;
        foreach ($ctx->ads(14)->sortByDesc('conversion_value') as $ad) {
            $c = $campaigns->get($ad['campaign_id']);
            if (! $c || $ad['conversions'] < 5 || ! $ad['roas'] || ! $c['roas'] || $ad['roas'] < 1.5 * $c['roas']) {
                continue;
            }
            if (++$found > 3) {
                break;
            }
            yield $this->finding([
                'platform' => $ad['platform'],
                'severity' => 'low',
                'kind' => 'opportunity',
                'entity_type' => 'ad', 'entity_id' => $ad['id'], 'entity_name' => $ad['name'],
                'title' => "Najlepsza reklama: „{$ad['name']}” (ROAS ".round($ad['roas'] * 100).'%)',
                'description' => "ROAS reklamy {$ad['roas']} vs {$c['roas']} w kampanii „{$c['name']}”. Konwersje: {$ad['conversions']}, CTR {$ad['ctr']}%.",
                'action' => $ad['platform'] === 'meta_ads'
                    ? 'Zduplikuj kreację do zestawu prospectingowego / Advantage+, przygotuj 2–3 warianty z tym samym przekazem (inne formaty: Reels, karuzela).'
                    : 'Wykorzystaj nagłówki i teksty tej reklamy w pozostałych grupach reklam; przypnij najlepszy nagłówek tylko jeśli to konieczne.',
            ]);
        }
    }
}
