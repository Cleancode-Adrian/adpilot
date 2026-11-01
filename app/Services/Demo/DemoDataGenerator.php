<?php

namespace App\Services\Demo;

use App\Models\Client;
use App\Models\TrackingAudit;
use App\Services\Connectors\MetricsWriter;
use Carbon\CarbonImmutable;

/**
 * Generuje realistyczne dane testowe (60 dni) dla klienta demo:
 * zwycięskie kampanie, przepalony budżet, wypalona kreacja, słabe hasła itd.
 * Dzięki temu silnik rekomendacji i raporty można testować bez dostępu do API.
 */
class DemoDataGenerator
{
    private const DAYS = 60;

    public static function createClient(): Client
    {
        $client = Client::create([
            'name' => 'DEMO – Ogrodowy Raj (meble ogrodowe)',
            'website_url' => 'https://demo-ogrodowyraj.pl',
            'industry' => 'E-commerce – dom i ogród',
            'currency' => 'PLN',
            'target_roas' => 5.0,
            'target_cpa' => 120,
            'monthly_budget' => 38000,
            'contact_email' => 'klient@example.com',
            'is_demo' => true,
            'notes' => 'Klient demonstracyjny z wygenerowanymi danymi.',
        ]);

        foreach (['google' => ['customer_id' => '123-456-7890', 'ga4_property_id' => '987654321'], 'meta' => ['ad_account_id' => 'act_1234567890']] as $provider => $settings) {
            $client->integrations()->create(['provider' => $provider, 'status' => 'connected', 'settings' => $settings + ['demo' => true]]);
        }

        (new self)->generate($client);

        return $client;
    }

    /** Klient demo nastawiony na pozyskiwanie kontaktów (formularze, telefony, rejestracje). */
    public static function createLeadsClient(): Client
    {
        $client = Client::create([
            'name' => 'DEMO – Biuro rachunkowe Bilans (kontakty)',
            'website_url' => 'https://demo-biurobilans.pl',
            'industry' => 'Usługi księgowe B2B',
            'goal' => 'leads',
            'currency' => 'PLN',
            'target_cpa' => 60,
            'daily_budget' => 45,
            'monthly_budget' => 1368,
            'contact_email' => 'klient@example.com',
            'is_demo' => true,
            'notes' => 'Klient demonstracyjny – kampanie na formularze kontaktowe i telefony.',
        ]);
        foreach (['google' => ['customer_id' => '222-333-4444', 'ga4_property_id' => '123123123'], 'meta' => ['ad_account_id' => 'act_9988776655']] as $provider => $settings) {
            $client->integrations()->create(['provider' => $provider, 'status' => 'connected', 'settings' => $settings + ['demo' => true]]);
        }
        (new self)->generate($client);

        return $client;
    }

    public function generate(Client $client): void
    {
        mt_srand(crc32($client->id.'adpilot'));
        $w = new MetricsWriter($client);
        $end = CarbonImmutable::yesterday();
        $start = $end->subDays(self::DAYS - 1);

        $leads = $client->goal === 'leads';
        $campaigns = $leads ? $this->leadDefinitions() : $this->definitions();
        $models = [];
        foreach ($campaigns as $key => $def) {
            $models[$key] = $w->campaign($def['platform'], 'demo-'.$key, [
                'name' => $def['name'], 'status' => $def['status'] ?? 'ENABLED', 'channel_type' => $def['type'], 'daily_budget' => $def['budget'],
            ]);
        }

        $paid = [];
        for ($day = $start, $i = 0; $day->lte($end); $day = $day->addDay(), $i++) {
            $date = $day->toDateString();
            $weekday = [1 => 1.0, 2 => 1.05, 3 => 1.0, 4 => 0.95, 5 => 0.9, 6 => 0.85, 7 => 1.15][$day->dayOfWeekIso];
            $season = 1 + 0.15 * sin($i / 9);

            foreach ($campaigns as $key => $def) {
                $c = $models[$key];
                $daySum = ['impressions' => 0, 'clicks' => 0, 'cost' => 0, 'conversions' => 0, 'conversion_value' => 0, 'reach' => 0];

                foreach ($def['ads'] as $adKey => $ad) {
                    $progress = $i / (self::DAYS - 1); // 0..1
                    $ctrMult = isset($ad['fatigue']) ? max(0.35, 1.25 - max(0, $progress - 0.55) * 2.4) : 1;
                    $freq = $def['platform'] === 'meta_ads'
                        ? round(($ad['freq'] ?? 1.6) + (isset($ad['fatigue']) ? max(0, $progress - 0.5) * 6 : 0) + $this->noise(0.1), 2)
                        : null;

                    $cost = $def['cost'] * $ad['share'] * $weekday * $season * (1 + $this->noise(0.15));
                    $cpc = $def['cpc'] * (1 + $this->noise(0.1)) / max(0.6, $ctrMult);
                    $clicks = max(0, (int) round($cost / $cpc));
                    $ctr = $def['ctr'] * $ctrMult * (1 + $this->noise(0.08));
                    $impr = (int) round($clicks / max(0.001, $ctr / 100));
                    $conv = $this->poisson($clicks * $def['cvr'] / 100 * ($ad['cvr'] ?? 1) * min(1, $ctrMult + 0.2));
                    $value = $conv * $def['aov'] * (1 + $this->noise(0.2));

                    $metrics = ['impressions' => $impr, 'clicks' => $clicks, 'cost' => $cost, 'conversions' => $conv, 'conversion_value' => $value, 'frequency' => $freq];
                    $adModel = $w->ad($def['platform'], "demo-$key-$adKey", $c->id, $ad['name'], 'ENABLED');
                    $w->adMetric($adModel->id, $date, $metrics);

                    foreach (['impressions', 'clicks', 'cost', 'conversions', 'conversion_value'] as $k) {
                        $daySum[$k] += $metrics[$k];
                    }
                    if ($freq) {
                        $daySum['reach'] += (int) round($impr / $freq);
                    }
                }

                $w->campaignMetric($c->id, $date, [
                    'impressions' => $daySum['impressions'], 'clicks' => $daySum['clicks'], 'cost' => $daySum['cost'],
                    'conversions' => $daySum['conversions'], 'conversion_value' => $daySum['conversion_value'],
                    'reach' => $daySum['reach'] ?: null,
                    'frequency' => $daySum['reach'] ? round($daySum['impressions'] / $daySum['reach'], 2) : null,
                    'budget_lost_is' => $def['platform'] === 'google_ads' ? max(0, ($def['lost_is'] ?? 0) + $this->noise(0.04)) : null,
                ]);

                $paid[$date][$def['ga_channel']]['clicks'] = ($paid[$date][$def['ga_channel']]['clicks'] ?? 0) + $daySum['clicks'];
                $paid[$date][$def['ga_channel']]['conv'] = ($paid[$date][$def['ga_channel']]['conv'] ?? 0) + $daySum['conversions'] * ($def['platform'] === 'meta_ads' ? 0.55 : 0.85);
            }

            $leads
                ? $this->leadAnalyticsDay($w, $date, $weekday * $season, $paid[$date])
                : $this->analyticsDay($w, $date, $weekday * $season, $paid[$date]);
        }

        $leads ? $this->leadSearchTerms($w, $models, $end) : $this->searchTerms($w, $models, $end);
        $this->demoAudit($client, $leads);
    }

    private function analyticsDay(MetricsWriter $w, string $date, float $factor, array $paid): void
    {
        $organic = [
            'Organic Search' => [620, 1.3], 'Direct' => [260, 1.9], 'Organic Social' => [85, 0.5],
            'Email' => [55, 3.1], 'Referral' => [40, 1.1],
        ];
        foreach ($organic as $channel => [$sessions, $cvr]) {
            $s = (int) round($sessions * $factor * (1 + $this->noise(0.12)));
            $tx = $this->poisson($s * $cvr / 100);
            $w->analytics($date, $channel, $this->gaRow($s, $tx));
        }
        foreach ($paid as $channel => $p) {
            $s = (int) round($p['clicks'] * 0.88);
            $tx = $this->poisson($p['conv']);
            $w->analytics($date, $channel, $this->gaRow($s, $tx));
        }
    }

    /** GA4 dla strony usługowej: brak transakcji, są zdarzenia leadowe. */
    private function leadAnalyticsDay(MetricsWriter $w, string $date, float $factor, array $paid): void
    {
        $leadsTotal = 0;
        $organic = ['Organic Search' => [140, 2.2], 'Direct' => [60, 3.0], 'Referral' => [15, 2.0], 'Organic Social' => [20, 0.8]];
        foreach ($organic as $channel => [$sessions, $cvr]) {
            $s = (int) round($sessions * $factor * (1 + $this->noise(0.15)));
            $n = $this->poisson($s * $cvr / 100);
            $leadsTotal += $n;
            $w->analytics($date, $channel, ['sessions' => $s, 'users' => (int) round($s * 0.85), 'new_users' => (int) round($s * 0.7),
                'engaged_sessions' => (int) round($s * 0.58), 'transactions' => 0, 'revenue' => 0, 'key_events' => $n]);
        }
        foreach ($paid as $channel => $p) {
            $s = (int) round($p['clicks'] * 0.9);
            $n = $this->poisson($p['conv']);
            $leadsTotal += $n;
            $w->analytics($date, $channel, ['sessions' => $s, 'users' => (int) round($s * 0.9), 'new_users' => (int) round($s * 0.8),
                'engaged_sessions' => (int) round($s * 0.5), 'transactions' => 0, 'revenue' => 0, 'key_events' => $n]);
        }
        // podział kontaktów na typy zdarzeń
        $form = (int) round($leadsTotal * 0.55);
        $phone = (int) round($leadsTotal * 0.35);
        $w->event($date, 'generate_lead', $form);
        $w->event($date, 'phone_click', $phone);
        $w->event($date, 'sign_up', max(0, $leadsTotal - $form - $phone));
    }

    private function leadSearchTerms(MetricsWriter $w, array $models, CarbonImmutable $end): void
    {
        $terms = [
            ['l_city', 'biuro rachunkowe warszawa', 210, 640, 14], ['l_city', 'księgowa warszawa mokotów', 95, 300, 7],
            ['l_city', 'biuro rachunkowe cennik', 120, 350, 5], ['l_service', 'księgowość dla jdg', 160, 420, 9],
            ['l_service', 'pełna księgowość spółka', 75, 260, 4], ['l_service', 'kadry i płace outsourcing', 60, 190, 2],
            ['l_city', 'biuro rachunkowe praca', 70, 160, 0], ['l_city', 'kurs księgowości', 55, 140, 0],
            ['l_service', 'jak samemu prowadzić księgowość', 48, 120, 0], ['l_service', 'darmowy program do faktur', 90, 210, 0],
            ['l_city', 'biuro rachunkowe opinie', 40, 95, 1],
        ];
        $rows = [];
        foreach ($terms as [$camp, $term, $clicks, $cost, $conv]) {
            $rows[] = ['term' => $term, 'campaign_id' => $models[$camp]->id, 'impressions' => $clicks * 12, 'clicks' => $clicks, 'cost' => $cost, 'conversions' => $conv, 'conversion_value' => 0];
        }
        $w->replaceSearchTerms($end->subDays(29)->toDateString(), $end->toDateString(), $rows);
    }

    private function leadDefinitions(): array
    {
        return [
            'l_city' => ['platform' => 'google_ads', 'name' => 'Search | Biuro rachunkowe Warszawa', 'type' => 'SEARCH', 'budget' => 20, 'cost' => 20, 'cpc' => 3.1, 'ctr' => 7.5, 'cvr' => 6.5, 'aov' => 0, 'lost_is' => 0.35, 'ga_channel' => 'Paid Search',
                'ads' => ['a' => ['name' => 'RSA – Pierwszy miesiąc gratis', 'share' => 0.6, 'cvr' => 1.3], 'b' => ['name' => 'RSA – Księgowa online', 'share' => 0.4, 'cvr' => 0.55]]],
            'l_service' => ['platform' => 'google_ads', 'name' => 'Search | Księgowość dla firm (usługi)', 'type' => 'SEARCH', 'budget' => 10, 'cost' => 10, 'cpc' => 2.6, 'ctr' => 5.8, 'cvr' => 4.2, 'aov' => 0, 'lost_is' => 0.12, 'ga_channel' => 'Paid Search',
                'ads' => ['a' => ['name' => 'RSA – JDG od 199 zł', 'share' => 1]]],
            'l_meta_form' => ['platform' => 'meta_ads', 'name' => 'Lead Ads | Formularz – darmowa wycena', 'type' => 'OUTCOME_LEADS', 'budget' => 10, 'cost' => 10, 'cpc' => 0.9, 'ctr' => 1.4, 'cvr' => 5.0, 'aov' => 0, 'ga_channel' => 'Paid Social',
                'ads' => ['a' => ['name' => 'Wideo – właściciel biura (UGC)', 'share' => 0.55, 'cvr' => 1.3, 'fatigue' => true, 'freq' => 1.9], 'b' => ['name' => 'Grafika – „Nie trać czasu na papiery”', 'share' => 0.45, 'cvr' => 0.6]]],
            'l_meta_traffic' => ['platform' => 'meta_ads', 'name' => 'Ruch | Strona – kalkulator kosztów', 'type' => 'OUTCOME_TRAFFIC', 'budget' => 5, 'cost' => 5, 'cpc' => 0.45, 'ctr' => 1.9, 'cvr' => 0.6, 'aov' => 0, 'ga_channel' => 'Paid Social',
                'ads' => ['a' => ['name' => 'Karuzela – 3 pakiety usług', 'share' => 1]]],
        ];
    }

    private function gaRow(int $sessions, float $tx): array
    {
        return [
            'sessions' => $sessions,
            'users' => (int) round($sessions * 0.82),
            'new_users' => (int) round($sessions * 0.61),
            'engaged_sessions' => (int) round($sessions * (0.55 + $this->noise(0.05))),
            'transactions' => $tx,
            'revenue' => $tx * 610 * (1 + $this->noise(0.15)),
        ];
    }

    private function searchTerms(MetricsWriter $w, array $models, CarbonImmutable $end): void
    {
        $terms = [
            ['brand', 'ogrodowy raj', 220, 950, 58, 24000], ['brand', 'ogrodowy raj opinie', 30, 110, 5, 2100],
            ['category', 'meble ogrodowe technorattan', 640, 1650, 21, 13400], ['category', 'zestaw mebli ogrodowych', 520, 1290, 16, 10900],
            ['category', 'leżak ogrodowy', 260, 610, 6, 2900], ['category', 'parasol ogrodowy duży', 180, 420, 4, 2300],
            ['category', 'meble ogrodowe ikea', 140, 390, 0, 0], ['category', 'meble ogrodowe używane', 95, 260, 0, 0],
            ['category', 'jak zrobić meble ogrodowe z palet', 70, 180, 0, 0], ['category', 'meble ogrodowe allegro', 110, 300, 0, 0],
            ['category', 'naprawa mebli ogrodowych', 45, 130, 0, 0], ['competitor', 'jysk meble ogrodowe', 160, 560, 0, 0],
            ['competitor', 'leroy merlin meble ogrodowe', 190, 640, 0, 0], ['competitor', 'castorama leżak', 85, 290, 0, 0],
            ['competitor', 'obi parasol', 60, 210, 0, 0], ['category', 'meble balkonowe', 150, 380, 3, 1500],
            ['category', 'stół ogrodowy rozkładany', 120, 330, 4, 2600], ['category', 'meble ogrodowe darmo', 40, 110, 0, 0],
        ];
        $map = ['brand' => 'g_brand', 'category' => 'g_category', 'competitor' => 'g_competitor'];
        $rows = [];
        foreach ($terms as [$camp, $term, $clicks, $cost, $conv, $value]) {
            $rows[] = ['term' => $term, 'campaign_id' => $models[$map[$camp]]->id, 'impressions' => $clicks * 14, 'clicks' => $clicks, 'cost' => $cost, 'conversions' => $conv, 'conversion_value' => $value];
        }
        $w->replaceSearchTerms($end->subDays(29)->toDateString(), $end->toDateString(), $rows);
    }

    private function demoAudit(Client $client, bool $leads = false): void
    {
        $client->audits()->delete();
        $checks = [
            ['key' => 'https', 'group' => 'Fundament', 'label' => 'Strona działa na HTTPS', 'status' => 'ok', 'details' => 'Certyfikat SSL aktywny.', 'fix' => null, 'weight' => 8],
            ['key' => 'gtm', 'group' => 'Fundament', 'label' => 'Google Tag Manager', 'status' => 'ok', 'details' => 'Wykryto: GTM-DEMO123', 'fix' => null, 'weight' => 6],
            ['key' => 'ga4', 'group' => 'Google Analytics 4', 'label' => 'Tag GA4', 'status' => 'ok', 'details' => 'Identyfikatory pomiaru: G-DEMO4567', 'fix' => null, 'weight' => 15],
            ['key' => 'purchase_event', 'group' => 'Google Analytics 4', 'label' => 'Zdarzenie purchase w GTM', 'status' => 'ok', 'details' => 'Kontener zawiera konfigurację zdarzenia purchase.', 'fix' => null, 'weight' => 12],
            ['key' => 'google_ads_tag', 'group' => 'Google Ads', 'label' => 'Tag Google Ads (AW-)', 'status' => 'ok', 'details' => 'Wykryto: AW-1234567890', 'fix' => null, 'weight' => 10],
            ['key' => 'conversion_linker', 'group' => 'Google Ads', 'label' => 'Conversion Linker', 'status' => 'ok', 'details' => 'Tag Conversion Linker obecny w GTM.', 'fix' => null, 'weight' => 5],
            ['key' => 'enhanced_conversions', 'group' => 'Google Ads', 'label' => 'Konwersje rozszerzone (Enhanced Conversions)', 'status' => 'warn', 'details' => 'Nie wykryto konwersji rozszerzonych.', 'fix' => 'Włącz Enhanced Conversions – odzyskuje zwykle kilka–kilkanaście % konwersji utraconych przez cookies.', 'weight' => 6],
            ['key' => 'meta_pixel', 'group' => 'Meta (Facebook/Instagram)', 'label' => 'Meta Pixel', 'status' => 'ok', 'details' => 'Pixel ID: 1122334455667788', 'fix' => null, 'weight' => 10],
            ['key' => 'meta_capi', 'group' => 'Meta (Facebook/Instagram)', 'label' => 'Conversions API (CAPI)', 'status' => 'info', 'details' => 'CAPI działa po stronie serwera – nie da się go wykryć z kodu strony.', 'fix' => 'Zweryfikuj w Menedżerze zdarzeń jakość dopasowania i deduplikację.', 'weight' => 0],
            ['key' => 'cmp', 'group' => 'Zgody (RODO)', 'label' => 'Baner zgód (CMP)', 'status' => 'ok', 'details' => 'Wykryto: CookieYes', 'fix' => null, 'weight' => 10],
            ['key' => 'consent_mode', 'group' => 'Zgody (RODO)', 'label' => 'Google Consent Mode v2', 'status' => 'fail', 'details' => 'Brak sygnałów Consent Mode (consent default).', 'fix' => 'Skonfiguruj Consent Mode v2 (ad_storage, analytics_storage, ad_user_data, ad_personalization). Bez tego Google Ads traci remarketing i modelowanie konwersji w UE.', 'weight' => 12],
            ['key' => 'viewport', 'group' => 'Strona i SEO', 'label' => 'Wersja mobilna (viewport)', 'status' => 'ok', 'details' => 'Meta viewport obecny.', 'fix' => null, 'weight' => 5],
            ['key' => 'pagespeed', 'group' => 'Szybkość', 'label' => 'PageSpeed (mobile)', 'status' => 'warn', 'details' => 'Wynik 52/100, LCP 4.1 s, CLS 0.08, TBT 640 ms.', 'fix' => 'Optymalizuj obrazy (WebP/AVIF), lazy-load, ogranicz skrypty zewnętrzne.', 'weight' => 8],
        ];
        if ($leads) {
            $checks = array_values(array_filter($checks, fn ($c) => $c['key'] !== 'purchase_event'));
            array_splice($checks, 4, 0, [
                ['key' => 'lead_event', 'group' => 'Kontakty (leady)', 'label' => 'Mierzenie wysłania formularza / rejestracji', 'status' => 'ok', 'details' => 'Wykryto zdarzenie leadowe (np. generate_lead, form_submit, sign_up).', 'fix' => null, 'weight' => 15],
                ['key' => 'forms', 'group' => 'Kontakty (leady)', 'label' => 'Formularz na stronie głównej', 'status' => 'ok', 'details' => 'Liczba formularzy: 1.', 'fix' => null, 'weight' => 4],
                ['key' => 'tel_links', 'group' => 'Kontakty (leady)', 'label' => 'Klikalny numer telefonu', 'status' => 'warn', 'details' => 'Brak linków tel: – na telefonie nie da się zadzwonić jednym kliknięciem.', 'fix' => 'Zamień numer na link <a href="tel:+48..."> i mierz kliknięcia jako zdarzenie (np. phone_click) – to często 30–50% kontaktów z reklam na mobile.', 'weight' => 5],
                ['key' => 'meta_lead_event', 'group' => 'Kontakty (leady)', 'label' => 'Zdarzenie Lead w Meta Pixel', 'status' => 'ok', 'details' => 'Wykryto zdarzenie Lead/CompleteRegistration/Contact.', 'fix' => null, 'weight' => 8],
            ]);
        }
        $client->audits()->create([
            'url' => $client->website_url, 'status' => 'done', 'score' => 74,
            'results' => [
                'final_url' => $client->website_url, 'platform' => $leads ? 'WordPress' : 'WooCommerce', 'goal' => $leads ? 'leads' : 'sales', 'response_ms' => 640, 'page_kb' => 312,
                'detected' => ['gtm' => ['GTM-DEMO123'], 'ga4' => ['G-DEMO4567'], 'google_ads' => ['AW-1234567890'], 'meta_pixel' => ['1122334455667788'], 'cmp' => ['CookieYes'], 'others' => ['Microsoft Clarity']],
                'gtm_containers' => [], 'pagespeed' => ['performance' => 52, 'lcp_s' => 4.1, 'cls' => 0.08, 'tbt_ms' => 640],
                'checks' => $checks, 'score' => 74, 'demo' => true,
            ],
        ]);
    }

    private function definitions(): array
    {
        return [
            'g_brand' => ['platform' => 'google_ads', 'name' => 'Search | Brand', 'type' => 'SEARCH', 'budget' => 80, 'cost' => 60, 'cpc' => 0.8, 'ctr' => 18, 'cvr' => 9, 'aov' => 430, 'lost_is' => 0.02, 'ga_channel' => 'Paid Search',
                'ads' => ['a' => ['name' => 'RSA – Oficjalny sklep', 'share' => 0.7], 'b' => ['name' => 'RSA – Darmowa dostawa', 'share' => 0.3]]],
            'g_category' => ['platform' => 'google_ads', 'name' => 'Search | Meble ogrodowe – kategorie', 'type' => 'SEARCH', 'budget' => 250, 'cost' => 250, 'cpc' => 2.4, 'ctr' => 6.5, 'cvr' => 3.3, 'aov' => 660, 'lost_is' => 0.27, 'ga_channel' => 'Paid Search',
                'ads' => ['a' => ['name' => 'RSA – Technorattan -30%', 'share' => 0.55, 'cvr' => 1.25], 'b' => ['name' => 'RSA – Zestawy wypoczynkowe', 'share' => 0.3], 'c' => ['name' => 'RSA – Ogólna (stara)', 'share' => 0.15, 'cvr' => 0.25]]],
            'g_pmax' => ['platform' => 'google_ads', 'name' => 'PMax | Wszystkie produkty', 'type' => 'PERFORMANCE_MAX', 'budget' => 400, 'cost' => 400, 'cpc' => 1.3, 'ctr' => 1.4, 'cvr' => 1.35, 'aov' => 620, 'ga_channel' => 'Cross-network',
                'ads' => ['a' => ['name' => 'Grupa plików – Bestsellery', 'share' => 0.65], 'b' => ['name' => 'Grupa plików – Nowości', 'share' => 0.35, 'cvr' => 0.7]]],
            'g_competitor' => ['platform' => 'google_ads', 'name' => 'Search | Konkurencja', 'type' => 'SEARCH', 'budget' => 90, 'cost' => 90, 'cpc' => 3.4, 'ctr' => 3.1, 'cvr' => 0, 'aov' => 600, 'lost_is' => 0.05, 'ga_channel' => 'Paid Search',
                'ads' => ['a' => ['name' => 'RSA – Lepsze ceny niż w marketach', 'share' => 1]]],
            'g_shopping' => ['platform' => 'google_ads', 'name' => 'Shopping | Akcesoria', 'type' => 'SHOPPING', 'budget' => 120, 'cost' => 120, 'cpc' => 0.9, 'ctr' => 1.1, 'cvr' => 1.4, 'aov' => 140, 'ga_channel' => 'Paid Shopping',
                'ads' => ['a' => ['name' => 'Produkty – poduszki i pokrowce', 'share' => 1]]],
            'm_advantage' => ['platform' => 'meta_ads', 'name' => 'Prospecting | Advantage+ zakupy', 'type' => 'OUTCOME_SALES', 'budget' => 300, 'cost' => 300, 'cpc' => 1.1, 'ctr' => 1.6, 'cvr' => 1.45, 'aov' => 560, 'ga_channel' => 'Paid Social',
                'ads' => ['a' => ['name' => 'Wideo – zestaw Toscana (UGC)', 'share' => 0.45, 'cvr' => 1.2, 'fatigue' => true, 'freq' => 1.8], 'b' => ['name' => 'Karuzela – bestsellery', 'share' => 0.35, 'cvr' => 1.3], 'c' => ['name' => 'Grafika – nowości 2026', 'share' => 0.2, 'cvr' => 0.05]]],
            'm_retargeting' => ['platform' => 'meta_ads', 'name' => 'Retargeting | DPA 30 dni', 'type' => 'OUTCOME_SALES', 'budget' => 80, 'cost' => 80, 'cpc' => 0.7, 'ctr' => 2.4, 'cvr' => 1.3, 'aov' => 590, 'ga_channel' => 'Paid Social',
                'ads' => ['a' => ['name' => 'DPA – oglądane produkty', 'share' => 1, 'freq' => 3.1]]],
            'm_interests' => ['platform' => 'meta_ads', 'name' => 'Prospecting | Zainteresowania – ogród', 'type' => 'OUTCOME_SALES', 'budget' => 150, 'cost' => 150, 'cpc' => 1.4, 'ctr' => 1.0, 'cvr' => 0.4, 'aov' => 540, 'ga_channel' => 'Paid Social',
                'ads' => ['a' => ['name' => 'Grafika – rabat 15%', 'share' => 0.6], 'b' => ['name' => 'Wideo – aranżacje tarasu', 'share' => 0.4]]],
        ];
    }

    private function noise(float $amp): float
    {
        return (mt_rand() / mt_getrandmax() * 2 - 1) * $amp;
    }

    /** Losowanie Poissona – liczba konwersji w danym dniu. */
    private function poisson(float $lambda): int
    {
        if ($lambda <= 0) {
            return 0;
        }
        if ($lambda > 30) {
            return max(0, (int) round($lambda + sqrt($lambda) * $this->noise(1.5)));
        }
        $l = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $l);

        return $k - 1;
    }
}
