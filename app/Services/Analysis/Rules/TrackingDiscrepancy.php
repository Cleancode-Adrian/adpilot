<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Rozbieżność konwersji: platformy reklamowe vs GA4. */
class TrackingDiscrepancy extends Rule
{
    public function key(): string { return 'tracking_discrepancy'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        [$from, $to] = $ctx->range(14);
        $ga = $ctx->metrics->analyticsTotals($from, $to);
        if (! $ga['has_data']) {
            return;
        }
        $google = $ctx->metrics->totals($from, $to, 'google_ads');

        // Kampanie na kontakty: porównujemy konwersje Ads z formularzami/rejestracjami w GA4
        if ($ctx->client->goal === 'leads') {
            if ($google['conversions'] > 5 && $ga['leads'] === 0) {
                yield $this->finding([
                    'platform' => 'tracking', 'severity' => 'critical',
                    'entity_type' => 'tracking', 'entity_id' => 'ga4_no_leads',
                    'title' => 'GA4 nie rejestruje formularzy ani rejestracji',
                    'description' => "Google Ads raportuje {$google['conversions']} konwersji w 14 dni, a GA4 – 0 zdarzeń: ".implode(', ', $ctx->client->leadEventNames()).'.',
                    'action' => 'Dodaj w GTM zdarzenie generate_lead (wysłanie formularza / strona podziękowania) i oznacz je w GA4 jako kluczowe. Jeśli używasz innej nazwy zdarzenia – wpisz ją w ustawieniach klienta.',
                ]);
            }

            return;
        }
        $gaPaid = $ctx->metrics->channels($from, $to)
            ->filter(fn ($c) => in_array($c['channel'], ['Paid Search', 'Paid Shopping', 'Cross-network', 'Paid Social', 'Paid Other', 'Display'], true))
            ->sum('transactions');

        if ($google['conversions'] > 5 && $ga['transactions'] < 0.5) {
            yield $this->finding([
                'platform' => 'tracking',
                'severity' => 'critical',
                'entity_type' => 'tracking', 'entity_id' => 'ga4_no_purchase',
                'title' => 'GA4 nie rejestruje transakcji',
                'description' => "Google Ads raportuje {$google['conversions']} konwersji w 14 dni, a GA4 – 0 transakcji.",
                'action' => 'Sprawdź zdarzenie purchase w GA4 (DebugView / Tag Assistant) – najczęściej brak dataLayer ecommerce lub błędny wyzwalacz w GTM.',
            ]);

            return;
        }

        $adsConv = $google['conversions'];
        if ($adsConv >= 10 && $gaPaid > 0) {
            $ratio = $adsConv / $gaPaid;
            if ($ratio > 1.6 || $ratio < 0.5) {
                yield $this->finding([
                    'platform' => 'tracking',
                    'severity' => 'high',
                    'entity_type' => 'tracking', 'entity_id' => 'ads_vs_ga4',
                    'title' => 'Duża rozbieżność konwersji Google Ads vs GA4',
                    'description' => "Google Ads: {$adsConv} konwersji, GA4 (kanały płatne): ".round($gaPaid, 1).' transakcji w 14 dni (stosunek '.round($ratio, 2).'×).',
                    'action' => $ratio > 1
                        ? 'Sprawdź, czy w celach konwersji Ads nie ma zdublowanych akcji (np. tag Ads + import z GA4 jednocześnie jako „główne”), oraz czy nie są liczone mikrokonwersje (add_to_cart) jako główne.'
                        : 'Sprawdź tag konwersji Ads na stronie podziękowania, Conversion Linker i Consent Mode – Ads może gubić konwersje.',
                    'data' => ['ads' => $adsConv, 'ga4_paid' => $gaPaid],
                ]);
            }
        }
    }
}
