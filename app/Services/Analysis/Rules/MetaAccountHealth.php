<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;
use Carbon\Carbon;

/**
 * Stan konta Meta: ostrzeżenia, które Meta pokazuje przy kampaniach i zestawach reklam
 * (np. „zestaw danych nie jest połączony z witryną”) oraz piksel, który przestał wysyłać zdarzenia.
 */
class MetaAccountHealth extends Rule
{
    public function key(): string { return 'meta_account_health'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        if ($ctx->client->is_demo) {
            return;
        }
        $integration = $ctx->client->integration('meta');
        if (! $integration || $integration->status !== 'connected') {
            return;
        }

        // 1) Ostrzeżenia Meta przy aktywnych kampaniach
        $active = $ctx->client->campaigns()->where('platform', 'meta_ads')->whereNotNull('issues')
            ->whereIn('status', ['ACTIVE', 'IN_PROCESS', 'WITH_ISSUES'])->get();
        foreach ($active as $c) {
            $issues = collect($c->issues)->unique('summary');
            yield $this->finding([
                'platform' => 'meta_ads',
                'severity' => 'high',
                'entity_type' => 'campaign', 'entity_id' => $c->id.':issues', 'entity_name' => $c->name,
                'title' => "Meta zgłasza problem: „{$c->name}”",
                'description' => $issues->map(fn ($i) => "{$i['where']}: {$i['summary']}".($i['message'] && $i['message'] !== $i['summary'] ? " – {$i['message']}" : ''))->take(5)->implode("\n"),
                'action' => 'Otwórz kampanię w Menedżerze reklam – ostrzeżenie jest przy zestawie reklam. Częste: połącz zestaw danych (piksel) z domeną w Menedżerze zdarzeń, dokończ weryfikację reklamodawcy, popraw odrzuconą reklamę.',
            ]);
        }

        // 2) Piksel: czy wysyła zdarzenia (ważne tylko, gdy kampanie wydają pieniądze)
        $spend = $ctx->metrics->totals(...[...$ctx->range(3), 'meta_ads'])['cost'];
        if ($spend <= 0) {
            return;
        }
        $pixels = collect($integration->setting('pixels', []));
        if ($pixels->isEmpty()) {
            if ($integration->setting('pixels') !== null) { // lista pobrana, ale pusta
                yield $this->finding([
                    'platform' => 'tracking', 'severity' => 'critical',
                    'entity_type' => 'tracking', 'entity_id' => 'meta_no_pixel',
                    'title' => 'Konto reklamowe nie ma piksela Meta',
                    'description' => 'Kampanie wydają budżet, ale Meta nie ma zestawu danych (piksela) – nie zobaczy leadów ani zakupów i nie zoptymalizuje kampanii.',
                    'action' => 'Menedżer zdarzeń → Połącz źródła danych → Internet → utwórz piksel, dodaj go na stronę (GTM lub kod) i połącz z domeną.',
                ]);
            }

            return;
        }
        $fresh = $pixels->filter(fn ($p) => ! $p['unavailable'] && $p['last_fired_time'] && Carbon::parse($p['last_fired_time'])->gt(now()->subHours(72)));
        if ($fresh->isEmpty()) {
            $last = $pixels->pluck('last_fired_time')->filter()->map(fn ($t) => Carbon::parse($t))->max();
            yield $this->finding([
                'platform' => 'tracking', 'severity' => 'critical',
                'entity_type' => 'tracking', 'entity_id' => 'meta_pixel_silent',
                'title' => 'Piksel Meta nie wysyła zdarzeń',
                'description' => 'Kampanie wydały '.$ctx->money($spend).' w 3 dni, a piksel '.($last ? 'ostatnio zadziałał '.$last->format('d.m.Y H:i') : 'nigdy nie wysłał zdarzenia').'. Bez tego Meta nie widzi wyników.',
                'action' => 'Sprawdź Meta Pixel Helper na stronie, czy baner cookies nie blokuje piksela po zgodzie i czy kod piksela nie zniknął po zmianach na stronie. Rozważ Conversions API (zdarzenia z serwera).',
            ]);
        }
    }
}
