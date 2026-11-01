<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Zerwane lub wygasające połączenia – bez nich dane i rekomendacje przestają być aktualne. */
class IntegrationHealth extends Rule
{
    public function key(): string { return 'integration_health'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        if ($ctx->client->is_demo) {
            return;
        }
        foreach ($ctx->client->integrations()->get() as $i) {
            $name = $i->provider === 'google' ? 'Google Ads / GA4' : 'Meta Ads';

            if ($i->last_error) {
                yield $this->finding([
                    'platform' => 'account', 'severity' => 'critical',
                    'entity_type' => 'integration', 'entity_id' => $i->provider.'_error',
                    'title' => "Połączenie {$name} nie działa",
                    'description' => mb_substr($i->last_error, 0, 300),
                    'action' => 'Wejdź w zakładkę Integracje i kliknij „Połącz ponownie”. Sprawdź, czy konto ma nadal dostęp do kont reklamowych klienta.',
                ]);
            } elseif ($i->status !== 'connected') {
                yield $this->finding([
                    'platform' => 'account', 'severity' => 'high',
                    'entity_type' => 'integration', 'entity_id' => $i->provider.'_pending',
                    'title' => "Wybierz konto {$name}",
                    'description' => 'Logowanie się udało, ale nie wybrano konta reklamowego / usługi GA4 – dane nie są pobierane.',
                    'action' => 'Zakładka Integracje → wybierz konto z listy → „Zapisz i synchronizuj”.',
                ]);
            } elseif ($i->provider === 'meta' && $i->token_expires_at && $i->token_expires_at->lt(now()->addDays(7))) {
                yield $this->finding([
                    'platform' => 'account', 'severity' => 'high',
                    'entity_type' => 'integration', 'entity_id' => 'meta_expiring',
                    'title' => 'Token Meta wygasa '.$i->token_expires_at->diffForHumans(),
                    'description' => 'Token dostępu Meta jest ważny ok. 60 dni. Po wygaśnięciu dane przestaną się pobierać.',
                    'action' => 'Zakładka Integracje → „Połącz ponownie” przy Meta (zajmuje 30 sekund).',
                ]);
            }
        }
    }
}
