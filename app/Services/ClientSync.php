<?php

namespace App\Services;

use App\Mail\IntegrationAlertMail;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use App\Services\Analysis\RuleEngine;
use App\Services\Connectors\Ga4Connector;
use App\Services\Connectors\GoogleAdsConnector;
use App\Services\Connectors\MetaConnector;
use App\Services\Connectors\MetricsWriter;
use App\Services\Connectors\SearchConsoleConnector;
use App\Services\Demo\DemoDataGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Pobiera dane ze wszystkich podłączonych źródeł, a potem uruchamia silnik reguł. */
class ClientSync
{
    public function __construct(
        private GoogleAdsConnector $googleAds,
        private Ga4Connector $ga4,
        private MetaConnector $meta,
        private RuleEngine $engine,
    ) {}

    /**
     * Pełna: ostatnie 30 dni + dziś, potem reguły.
     * Szybka (co godzinę): tylko wczoraj i dziś – świeże wydatki i kliknięcia, bez przeliczania reguł.
     */
    public function sync(Client $client, bool $quick = false): array
    {
        $log = [];

        if ($client->is_demo && $quick) {
            return ['Demo – pomijam szybką synchronizację.'];
        }
        if ($client->is_demo) {
            (new DemoDataGenerator)->generate($client);
            $client->integrations()->update(['last_synced_at' => now(), 'last_error' => null]);
            $log[] = 'Dane demo wygenerowane ponownie.';
        } else {
            // dziś też – dane niepełne, ale nadpisują się przy każdej kolejnej synchronizacji
            $to = now()->toDateString();
            $from = $quick ? now()->subDay()->toDateString() : now()->subDays(config('adpilot.sync_lookback_days'))->toDateString();
            $writer = new MetricsWriter($client);

            foreach ($client->integrations()->where('status', 'connected')->get() as $integration) {
                try {
                    if ($integration->provider === 'google') {
                        if ($integration->setting('customer_id')) {
                            $this->googleAds->sync($integration, $writer, $from, $to);
                            $log[] = 'Google Ads: OK';
                        }
                        if ($integration->setting('ga4_property_id')) {
                            $this->ga4->sync($integration, $writer, $from, $to);
                            $log[] = 'GA4: OK';
                        }
                        if (! $quick && $integration->setting('gsc_site')) {
                            app(SearchConsoleConnector::class)->sync($client);
                            $log[] = 'Search Console: OK';
                        }
                    } elseif ($integration->provider === 'meta') {
                        $this->meta->sync($integration, $writer, $from, $to);
                        $log[] = 'Meta Ads: OK';
                    }
                    $integration->update(['last_synced_at' => now(), 'last_error' => null, 'alerted_at' => null]);
                } catch (Throwable $e) {
                    Log::warning("Sync {$integration->provider} klienta {$client->id}: ".$e->getMessage());
                    $integration->update(['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                    $this->alert($integration);
                    $log[] = ucfirst($integration->provider).': BŁĄD – '.$e->getMessage();
                }
            }
        }

        if ($quick) {
            return $log;
        }

        $stats = $this->engine->run($client);
        $log[] = "Rekomendacje: {$stats['new']} nowych, {$stats['updated']} zaktualizowanych, {$stats['resolved']} rozwiązanych.";

        return $log;
    }

    /** E-mail do administratorów – maks. raz na dobę dla danej integracji. */
    private function alert(Integration $integration): void
    {
        if ($integration->alerted_at && $integration->alerted_at->gt(now()->subDay())) {
            return;
        }
        $to = User::where('is_admin', true)->pluck('email')->all() ?: array_filter([Setting::brand()['email']]);
        if ($to) {
            try {
                Mail::to($to)->send(new IntegrationAlertMail($integration->load('client')));
                $integration->update(['alerted_at' => now()]);
            } catch (Throwable $e) {
                Log::warning('Alert integracji nie wysłany: '.$e->getMessage());
            }
        }
    }
}
