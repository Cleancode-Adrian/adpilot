<?php

namespace App\Services\Connectors;

use App\Models\Ad;
use App\Models\AdMetric;
use App\Models\AnalyticsDaily;
use App\Models\Campaign;
use App\Models\CampaignMetric;
use App\Models\Client;
use App\Models\SearchTerm;

/**
 * Wspólny zapis danych – konektory (Google Ads, Meta, GA4) i generator demo
 * zapisują dane w jednym, ujednoliconym formacie.
 */
class MetricsWriter
{
    /** @var array<string, int> */
    private array $campaignIds = [];

    public function __construct(private Client $client) {}

    public function campaign(string $platform, string $externalId, array $attrs): Campaign
    {
        $campaign = Campaign::updateOrCreate(
            ['client_id' => $this->client->id, 'platform' => $platform, 'external_id' => $externalId],
            $attrs,
        );
        $this->campaignIds["$platform|$externalId"] = $campaign->id;

        return $campaign;
    }

    public function campaignId(string $platform, string $externalId): ?int
    {
        if (! isset($this->campaignIds["$platform|$externalId"])) {
            $id = Campaign::where(['client_id' => $this->client->id, 'platform' => $platform, 'external_id' => $externalId])->value('id');
            if ($id) {
                $this->campaignIds["$platform|$externalId"] = $id;
            }
        }

        return $this->campaignIds["$platform|$externalId"] ?? null;
    }

    public function campaignMetric(int $campaignId, string $date, array $m): void
    {
        CampaignMetric::updateOrCreate(['campaign_id' => $campaignId, 'date' => $date], $this->clean($m));
    }

    public function ad(string $platform, string $externalId, int $campaignId, string $name, ?string $status): Ad
    {
        return Ad::updateOrCreate(
            ['client_id' => $this->client->id, 'platform' => $platform, 'external_id' => $externalId],
            ['campaign_id' => $campaignId, 'name' => $name, 'status' => $status],
        );
    }

    public function adMetric(int $adId, string $date, array $m): void
    {
        AdMetric::updateOrCreate(['ad_id' => $adId, 'date' => $date], $this->clean($m));
    }

    /** Hasła zastępujemy w całości dla danego okresu. */
    public function replaceSearchTerms(string $from, string $to, array $rows): void
    {
        SearchTerm::where('client_id', $this->client->id)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            SearchTerm::insert(array_map(fn ($r) => [
                'client_id' => $this->client->id,
                'campaign_id' => $r['campaign_id'] ?? null,
                'term' => mb_substr($r['term'], 0, 250),
                'period_start' => $from,
                'period_end' => $to,
                'impressions' => (int) ($r['impressions'] ?? 0),
                'clicks' => (int) ($r['clicks'] ?? 0),
                'cost' => round((float) ($r['cost'] ?? 0), 2),
                'conversions' => round((float) ($r['conversions'] ?? 0), 2),
                'conversion_value' => round((float) ($r['conversion_value'] ?? 0), 2),
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }

    public function analytics(string $date, string $channel, array $m): void
    {
        AnalyticsDaily::updateOrCreate(
            ['client_id' => $this->client->id, 'date' => $date, 'channel' => $channel],
            $this->clean($m),
        );
    }

    /** Umiejscowienia Meta (dzień × kampania × platforma × pozycja). */
    public function placement(int $campaignId, string $date, string $platform, string $position, array $m): void
    {
        \Illuminate\Support\Facades\DB::table('placement_metrics')->updateOrInsert(
            ['campaign_id' => $campaignId, 'date' => $date, 'publisher_platform' => $platform, 'platform_position' => $position],
            ['client_id' => $this->client->id] + $this->clean($m),
        );
    }

    /** Sesje GA4 wg źródła / medium. */
    public function source(string $date, string $sourceMedium, array $m): void
    {
        \Illuminate\Support\Facades\DB::table('analytics_sources')->updateOrInsert(
            ['client_id' => $this->client->id, 'date' => $date, 'source_medium' => mb_substr($sourceMedium, 0, 190)],
            $this->clean($m),
        );
    }

    public function event(string $date, string $name, int $count): void
    {
        \Illuminate\Support\Facades\DB::table('analytics_events')->updateOrInsert(
            ['client_id' => $this->client->id, 'date' => $date, 'event_name' => $name],
            ['count' => $count],
        );
    }

    private function clean(array $m): array
    {
        foreach (['cost', 'conversions', 'conversion_value', 'revenue', 'transactions', 'key_events'] as $k) {
            if (isset($m[$k])) {
                $m[$k] = round((float) $m[$k], 2);
            }
        }

        return $m;
    }
}
