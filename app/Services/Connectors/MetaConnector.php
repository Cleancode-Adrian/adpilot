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
        // Facebook Login for Business: uprawnienia definiuje „konfiguracja” (config_id) zamiast scope
        $permissions = filled(config('adpilot.meta.config_id'))
            ? ['config_id' => config('adpilot.meta.config_id')]
            : ['scope' => implode(',', config('adpilot.meta.scopes'))];

        return 'https://www.facebook.com/'.config('adpilot.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('adpilot.meta.app_id'),
            'redirect_uri' => route('oauth.meta.callback'),
            'state' => $state,
            'response_type' => 'code',
        ] + $permissions);
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

        // Ostrzeżenia Meta z zestawów reklam (np. „zestaw danych nie jest połączony z witryną”) – przypinamy do kampanii
        $adsetIssues = [];
        $destinations = [];
        try {
            foreach ($this->paginate($i, "$act/adsets", ['fields' => 'id,name,campaign_id,effective_status,issues_info,destination_type', 'limit' => 200]) as $s) {
                if (! empty($s['destination_type'])) {
                    $destinations[$s['campaign_id']][] = $s['destination_type'];
                }
                foreach ($this->issues($s['issues_info'] ?? [], 'zestaw „'.($s['name'] ?? $s['id']).'”') as $issue) {
                    $adsetIssues[$s['campaign_id']][] = $issue;
                }
            }
        } catch (RuntimeException) {
            // brak uprawnień do zestawów – nie blokuje synchronizacji
        }

        // Kampanie (status, budżet, ostrzeżenia)
        foreach ($this->paginate($i, "$act/campaigns", ['fields' => 'id,name,effective_status,objective,daily_budget,issues_info', 'limit' => 200]) as $c) {
            $issues = array_merge($this->issues($c['issues_info'] ?? [], 'kampania'), $adsetIssues[$c['id']] ?? []);
            $w->campaign(self::PLATFORM, $c['id'], [
                'name' => $c['name'],
                'status' => $c['effective_status'] ?? null,
                'channel_type' => $c['objective'] ?? null,
                'daily_budget' => isset($c['daily_budget']) ? $c['daily_budget'] / 100 : null,
                'issues' => $issues ?: null,
                // kampania mieszana (strona + formularz) liczona jako „strona”
                'destination' => isset($destinations[$c['id']]) ? (in_array('WEBSITE', $destinations[$c['id']], true) ? 'WEBSITE' : $destinations[$c['id']][0]) : null,
            ]);
        }

        // Insights na poziomie reklamy, dzień po dniu
        $insights = $this->paginate($i, "$act/insights", [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from, 'until' => $to]),
            'fields' => 'campaign_id,campaign_name,ad_id,ad_name,impressions,clicks,inline_link_clicks,spend,reach,frequency,actions,action_values',
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
                'link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
                'landing_page_views' => (int) $this->action($row['actions'] ?? [], 'landing_page_view'),
                'cost' => (float) ($row['spend'] ?? 0),
                'conversions' => $this->results($row['actions'] ?? [], $goal),
                'conversion_value' => $this->purchase($row['action_values'] ?? []),
                'frequency' => isset($row['frequency']) ? (float) $row['frequency'] : null,
            ];
            $w->adMetric($ad->id, $row['date_start'], $metrics);

            $agg = &$campaignDays[$campaignId][$row['date_start']];
            $agg ??= ['impressions' => 0, 'clicks' => 0, 'link_clicks' => 0, 'landing_page_views' => 0, 'cost' => 0, 'conversions' => 0, 'conversion_value' => 0, 'reach' => 0];
            foreach (['impressions', 'clicks', 'link_clicks', 'landing_page_views', 'cost', 'conversions', 'conversion_value'] as $k) {
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

        $this->syncPlacements($i, $w, $act, $from, $to, $goal);
        $this->syncPixels($i, $act);
    }

    /** Wyniki wg umiejscowień – tu widać np. kliknięcia z Audience Network bez wejść na stronę. */
    private function syncPlacements(Integration $i, MetricsWriter $w, string $act, string $from, string $to, string $goal): void
    {
        try {
            $rows = $this->paginate($i, "$act/insights", [
                'level' => 'campaign',
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $from, 'until' => $to]),
                'breakdowns' => 'publisher_platform,platform_position',
                'fields' => 'campaign_id,campaign_name,impressions,clicks,inline_link_clicks,spend,actions',
                'limit' => 500,
            ]);
        } catch (RuntimeException) {
            return; // podział niedostępny dla konta – nie blokuje reszty
        }
        foreach ($rows as $row) {
            $campaignId = $w->campaignId(self::PLATFORM, $row['campaign_id']);
            if (! $campaignId) {
                continue;
            }
            $w->placement($campaignId, $row['date_start'], (string) ($row['publisher_platform'] ?? 'unknown'), (string) ($row['platform_position'] ?? 'unknown'), [
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'link_clicks' => (int) ($row['inline_link_clicks'] ?? 0),
                'landing_page_views' => (int) $this->action($row['actions'] ?? [], 'landing_page_view'),
                'cost' => (float) ($row['spend'] ?? 0),
                'conversions' => $this->results($row['actions'] ?? [], $goal),
            ]);
        }
    }

    /** Piksele konta: kiedy ostatnio wysłały zdarzenie (piksel „milczy” = brak pomiaru konwersji). */
    private function syncPixels(Integration $i, string $act): void
    {
        try {
            $pixels = $this->paginate($i, "$act/adspixels", ['fields' => 'id,name,last_fired_time,is_unavailable', 'limit' => 50]);
        } catch (RuntimeException) {
            return;
        }
        $i->settings = array_merge($i->settings ?? [], ['pixels' => array_map(fn ($p) => [
            'id' => $p['id'],
            'name' => $p['name'] ?? $p['id'],
            'last_fired_time' => $p['last_fired_time'] ?? null,
            'unavailable' => (bool) ($p['is_unavailable'] ?? false),
        ], $pixels)]);
        $i->save();
    }

    /** issues_info Meta → [{where, summary, message, level}] */
    private function issues(array $issues, string $where): array
    {
        return array_values(array_filter(array_map(fn ($x) => is_array($x) ? [
            'where' => $where,
            'summary' => (string) ($x['error_summary'] ?? $x['error_message'] ?? 'Ostrzeżenie Meta'),
            'message' => (string) ($x['error_message'] ?? ''),
            'level' => (string) ($x['level'] ?? ''),
        ] : null, $issues)));
    }

    private function action(array $actions, string $type): float
    {
        foreach ($actions as $a) {
            if (($a['action_type'] ?? null) === $type) {
                return (float) $a['value'];
            }
        }

        return 0.0;
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
