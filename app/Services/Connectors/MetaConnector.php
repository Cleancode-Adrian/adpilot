<?php

namespace App\Services\Connectors;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta Marketing API (Facebook / Instagram Ads).
 * Wymaga aplikacji w Meta for Developers z uprawnieniem ads_read (App Review + weryfikacja firmy).
 */
class MetaConnector
{
    public const PLATFORM = 'meta_ads';

    public static function configured(): bool
    {
        return filled(config('adpilot.meta.app_id')) && filled(config('adpilot.meta.app_secret'));
    }

    private function graph(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('adpilot.meta.graph_version').'/'.ltrim($path, '/');
    }

    public function authUrl(string $state): string
    {
        return 'https://www.facebook.com/'.config('adpilot.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('adpilot.meta.app_id'),
            'redirect_uri' => route('oauth.meta.callback'),
            'state' => $state,
            'scope' => implode(',', config('adpilot.meta.scopes')),
            'response_type' => 'code',
        ]);
    }

    /** Wymienia kod na długoterminowy token (~60 dni). */
    public function exchangeCode(string $code): array
    {
        $short = Http::get($this->graph('oauth/access_token'), [
            'client_id' => config('adpilot.meta.app_id'),
            'client_secret' => config('adpilot.meta.app_secret'),
            'redirect_uri' => route('oauth.meta.callback'),
            'code' => $code,
        ]);
        if ($short->failed()) {
            throw new RuntimeException('Meta OAuth: '.$short->json('error.message', $short->body()));
        }

        $long = Http::get($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('adpilot.meta.app_id'),
            'client_secret' => config('adpilot.meta.app_secret'),
            'fb_exchange_token' => $short->json('access_token'),
        ]);

        return $long->successful() ? $long->json() : $short->json();
    }

    public function listAccounts(Integration $i): array
    {
        $rows = $this->paginate($i, 'me/adaccounts', ['fields' => 'account_id,name,currency,account_status', 'limit' => 200]);

        return array_map(fn ($a) => [
            'id' => 'act_'.$a['account_id'],
            'name' => $a['name'].' ('.($a['currency'] ?? '').')'.(($a['account_status'] ?? 1) != 1 ? ' – nieaktywne' : ''),
        ], $rows);
    }

    public function sync(Integration $i, MetricsWriter $w, string $from, string $to): void
    {
        $act = $i->setting('ad_account_id');
        if (! $act) {
            throw new RuntimeException('Nie wybrano konta reklamowego Meta.');
        }

        // Kampanie (status, budżet)
        foreach ($this->paginate($i, "$act/campaigns", ['fields' => 'id,name,effective_status,objective,daily_budget', 'limit' => 200]) as $c) {
            $w->campaign(self::PLATFORM, $c['id'], [
                'name' => $c['name'],
                'status' => $c['effective_status'] ?? null,
                'channel_type' => $c['objective'] ?? null,
                'daily_budget' => isset($c['daily_budget']) ? $c['daily_budget'] / 100 : null,
            ]);
        }

        // Insights na poziomie reklamy, dzień po dniu
        $insights = $this->paginate($i, "$act/insights", [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from, 'until' => $to]),
            'fields' => 'campaign_id,campaign_name,ad_id,ad_name,impressions,clicks,spend,reach,frequency,actions,action_values',
            'limit' => 500,
        ]);

        $goal = $i->client->goal ?? 'sales';
        $campaignDays = [];
        foreach ($insights as $row) {
            $campaignId = $w->campaignId(self::PLATFORM, $row['campaign_id'])
                ?? $w->campaign(self::PLATFORM, $row['campaign_id'], ['name' => $row['campaign_name']])->id;
            $ad = $w->ad(self::PLATFORM, $row['ad_id'], $campaignId, $row['ad_name'], null);

            $metrics = [
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'cost' => (float) ($row['spend'] ?? 0),
                'conversions' => $this->results($row['actions'] ?? [], $goal),
                'conversion_value' => $this->purchase($row['action_values'] ?? []),
                'frequency' => isset($row['frequency']) ? (float) $row['frequency'] : null,
            ];
            $w->adMetric($ad->id, $row['date_start'], $metrics);

            $agg = &$campaignDays[$campaignId][$row['date_start']];
            $agg ??= ['impressions' => 0, 'clicks' => 0, 'cost' => 0, 'conversions' => 0, 'conversion_value' => 0, 'reach' => 0];
            foreach (['impressions', 'clicks', 'cost', 'conversions', 'conversion_value'] as $k) {
                $agg[$k] += $metrics[$k];
            }
            $agg['reach'] += (int) ($row['reach'] ?? 0); // przybliżenie – reach nie sumuje się idealnie
            unset($agg);
        }

        foreach ($campaignDays as $campaignId => $days) {
            foreach ($days as $date => $m) {
                $m['frequency'] = $m['reach'] > 0 ? round($m['impressions'] / $m['reach'], 2) : null;
                $w->campaignMetric($campaignId, $date, $m);
            }
        }
    }

    /** Wynik wg celu klienta: zakupy, leady (formularz/rejestracja/kontakt) lub oba. */
    private function results(array $actions, string $goal): float
    {
        $total = 0.0;
        if ($goal !== 'leads') {
            $total += $this->purchase($actions);
        }
        if ($goal !== 'sales') {
            $byType = collect($actions)->keyBy('action_type');
            foreach (config('adpilot.meta.lead_action_groups') as $group) {
                foreach ($group as $type) {
                    if ($byType->has($type)) {
                        $total += (float) $byType[$type]['value'];
                        break;
                    }
                }
            }
        }

        return $total;
    }

    private function purchase(array $actions): float
    {
        $byType = collect($actions)->keyBy('action_type');
        foreach (config('adpilot.meta.purchase_actions') as $type) {
            if ($byType->has($type)) {
                return (float) $byType[$type]['value'];
            }
        }

        return 0.0;
    }

    private function paginate(Integration $i, string $path, array $params): array
    {
        $out = [];
        $url = $this->graph($path);
        $params['access_token'] = $i->access_token;
        $guard = 0;

        while ($url && $guard++ < 200) {
            $res = Http::timeout(120)->get($url, $params);
            if ($res->failed()) {
                throw new RuntimeException('Meta API: '.$res->json('error.message', $res->body()));
            }
            array_push($out, ...$res->json('data', []));
            $url = $res->json('paging.next');
            $params = []; // "next" zawiera już wszystkie parametry
        }

        return $out;
    }
}
