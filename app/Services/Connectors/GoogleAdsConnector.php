<?php

namespace App\Services\Connectors;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Ads API (REST, GAQL searchStream).
 * Wymaga developer tokena z konta MCC – bez niego API zwróci błąd DEVELOPER_TOKEN_NOT_APPROVED.
 */
class GoogleAdsConnector
{
    public const PLATFORM = 'google_ads';

    public function __construct(private GoogleAuth $auth) {}

    private function base(): string
    {
        return 'https://googleads.googleapis.com/'.config('adpilot.google.ads_api_version');
    }

    private function http(Integration $i)
    {
        $headers = ['developer-token' => (string) config('adpilot.google.ads_developer_token')];
        $login = $i->setting('login_customer_id') ?: config('adpilot.google.ads_login_customer_id');
        if ($login) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $login);
        }

        return Http::withToken($this->auth->token($i))->withHeaders($headers)->timeout(120);
    }

    /** Lista kont dostępnych dla zalogowanego użytkownika. */
    public function listAccounts(Integration $i): array
    {
        if (! config('adpilot.google.ads_developer_token')) {
            return [];
        }
        $res = $this->http($i)->get($this->base().'/customers:listAccessibleCustomers');
        if ($res->failed()) {
            throw new RuntimeException('Google Ads: '.$this->error($res->json()));
        }

        $accounts = [];
        foreach ($res->json('resourceNames', []) as $rn) {
            $id = str_replace('customers/', '', $rn);
            $name = $id;
            try {
                $rows = $this->query($i, $id, 'SELECT customer.descriptive_name, customer.currency_code, customer.manager FROM customer LIMIT 1');
                $c = $rows[0]['customer'] ?? [];
                if (! empty($c['manager'])) {
                    continue; // konta MCC pomijamy – nie mają kampanii
                }
                $name = ($c['descriptiveName'] ?? $id).' ('.($c['currencyCode'] ?? '').')';
            } catch (\Throwable) {
                // brak dostępu bez login-customer-id – zostawiamy samo ID
            }
            $accounts[] = ['id' => $id, 'name' => $name];
        }

        return $accounts;
    }

    public function sync(Integration $i, MetricsWriter $w, string $from, string $to): void
    {
        $cid = preg_replace('/\D/', '', (string) $i->setting('customer_id'));
        if (! $cid) {
            throw new RuntimeException('Nie wybrano konta Google Ads.');
        }

        // 1. Kampanie + dzienne metryki
        $rows = $this->query($i, $cid, "
            SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                   campaign_budget.amount_micros, segments.date,
                   metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions,
                   metrics.conversions_value, metrics.search_budget_lost_impression_share
            FROM campaign
            WHERE segments.date BETWEEN '$from' AND '$to' AND campaign.status != 'REMOVED'");

        foreach ($rows as $r) {
            $c = $r['campaign'];
            $campaign = $w->campaign(self::PLATFORM, (string) $c['id'], [
                'name' => $c['name'],
                'status' => $c['status'] ?? null,
                'channel_type' => $c['advertisingChannelType'] ?? null,
                'daily_budget' => isset($r['campaignBudget']['amountMicros']) ? $r['campaignBudget']['amountMicros'] / 1_000_000 : null,
            ]);
            $m = $r['metrics'] ?? [];
            $w->campaignMetric($campaign->id, $r['segments']['date'], [
                'impressions' => (int) ($m['impressions'] ?? 0),
                'clicks' => (int) ($m['clicks'] ?? 0),
                'cost' => ($m['costMicros'] ?? 0) / 1_000_000,
                'conversions' => (float) ($m['conversions'] ?? 0),
                'conversion_value' => (float) ($m['conversionsValue'] ?? 0),
                'budget_lost_is' => isset($m['searchBudgetLostImpressionShare']) ? (float) $m['searchBudgetLostImpressionShare'] : null,
            ]);
        }

        // 2. Reklamy (poziom ad_group_ad). PMax nie ma reklam na tym poziomie – to normalne.
        $rows = $this->query($i, $cid, "
            SELECT campaign.id, ad_group.name, ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.ad.type,
                   ad_group_ad.status, segments.date,
                   metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value
            FROM ad_group_ad
            WHERE segments.date BETWEEN '$from' AND '$to' AND ad_group_ad.status != 'REMOVED'");

        foreach ($rows as $r) {
            $campaignId = $w->campaignId(self::PLATFORM, (string) $r['campaign']['id']);
            if (! $campaignId) {
                continue;
            }
            $ad = $r['adGroupAd']['ad'];
            $name = ($ad['name'] ?? null) ?: (($r['adGroup']['name'] ?? 'Grupa').' – '.str_replace('_', ' ', strtolower($ad['type'] ?? 'reklama')).' #'.$ad['id']);
            $model = $w->ad(self::PLATFORM, (string) $ad['id'], $campaignId, $name, $r['adGroupAd']['status'] ?? null);
            $m = $r['metrics'] ?? [];
            $w->adMetric($model->id, $r['segments']['date'], [
                'impressions' => (int) ($m['impressions'] ?? 0),
                'clicks' => (int) ($m['clicks'] ?? 0),
                'cost' => ($m['costMicros'] ?? 0) / 1_000_000,
                'conversions' => (float) ($m['conversions'] ?? 0),
                'conversion_value' => (float) ($m['conversionsValue'] ?? 0),
            ]);
        }

        // 3. Wyszukiwane hasła – ostatnie 30 dni (do wykluczeń)
        $termsFrom = now()->subDays(30)->toDateString();
        $rows = $this->query($i, $cid, "
            SELECT search_term_view.search_term, campaign.id,
                   metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value
            FROM search_term_view
            WHERE segments.date BETWEEN '$termsFrom' AND '$to' AND metrics.cost_micros > 0
            ORDER BY metrics.cost_micros DESC
            LIMIT 5000");

        $terms = [];
        foreach ($rows as $r) {
            $key = $r['searchTermView']['searchTerm'].'|'.$r['campaign']['id'];
            $m = $r['metrics'];
            $terms[$key] ??= ['term' => $r['searchTermView']['searchTerm'], 'campaign_id' => $w->campaignId(self::PLATFORM, (string) $r['campaign']['id']), 'impressions' => 0, 'clicks' => 0, 'cost' => 0, 'conversions' => 0, 'conversion_value' => 0];
            $terms[$key]['impressions'] += (int) ($m['impressions'] ?? 0);
            $terms[$key]['clicks'] += (int) ($m['clicks'] ?? 0);
            $terms[$key]['cost'] += ($m['costMicros'] ?? 0) / 1_000_000;
            $terms[$key]['conversions'] += (float) ($m['conversions'] ?? 0);
            $terms[$key]['conversion_value'] += (float) ($m['conversionsValue'] ?? 0);
        }
        $w->replaceSearchTerms($termsFrom, $to, array_values($terms));
    }

    /** Wykonuje zapytanie GAQL i zwraca spłaszczoną listę wierszy. */
    public function query(Integration $i, string $customerId, string $gaql): array
    {
        $res = $this->http($i)->post($this->base()."/customers/{$customerId}/googleAds:searchStream", [
            'query' => preg_replace('/\s+/', ' ', trim($gaql)),
        ]);
        if ($res->failed()) {
            throw new RuntimeException('Google Ads: '.$this->error($res->json()));
        }

        $rows = [];
        foreach ((array) $res->json() as $batch) {
            foreach ($batch['results'] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function error(?array $json): string
    {
        $err = is_array($json) && array_is_list($json) ? ($json[0]['error'] ?? []) : ($json['error'] ?? []);
        $detail = data_get($err, 'details.0.errors.0.message');

        return $detail ?: ($err['message'] ?? 'nieznany błąd');
    }
}
