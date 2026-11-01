<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/**
 * Kampanie na kontakty: dużo kliknięć, mało formularzy → problem leży na stronie docelowej,
 * nie w reklamie (lub formularz nie jest mierzony).
 */
class LowLandingConversion extends Rule
{
    public function key(): string { return 'low_landing_conversion'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        if (! $ctx->client->tracksLeads()) {
            return;
        }
        foreach ($ctx->campaigns(14) as $c) {
            if ($c['clicks'] < 300 || $c['cvr'] >= 1.0 || $c['conversions'] < 0.5) {
                continue; // brak konwersji w ogóle obsługuje reguła WastedSpendCampaign
            }
            yield $this->finding([
                'platform' => $c['platform'],
                'severity' => 'medium',
                'entity_type' => 'campaign', 'entity_id' => $c['id'], 'entity_name' => $c['name'],
                'title' => "Mało zapytań z kliknięć w „{$c['name']}” ({$c['cvr']}%)",
                'description' => "{$c['clicks']} kliknięć i tylko {$c['conversions']} leadów w 14 dni. Dla kampanii na kontakty typowo 3–10% kliknięć kończy się wysłaniem formularza lub telefonem.",
                'action' => 'Sprawdź stronę docelową: czy formularz jest widoczny bez przewijania na telefonie, ma max. 3–5 pól, jest wyraźne CTA i numer telefonu jako link. Upewnij się, że wysłanie formularza jest mierzone. W Meta rozważ formularz natywny (Lead Ads).',
                'impact' => 'Podwojenie konwersji strony = o połowę niższy koszt leada przy tym samym budżecie',
            ]);
        }
    }
}
