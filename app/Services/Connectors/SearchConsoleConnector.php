<?php

namespace App\Services\Connectors;

use App\Models\Client;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Search Console (Search Analytics API): kliknięcia, wyświetlenia i pozycja z Google
 * dzień po dniu (90 dni) + najlepsze frazy i podstrony z 28 dni z porównaniem do poprzednich 28 dni.
 * Korzysta z tego samego połączenia Google co Ads/GA4 (zakres webmasters.readonly).
 */
class SearchConsoleConnector
{
    private const API = 'https://www.googleapis.com/webmasters/v3';

    public function __construct(private GoogleAuth $auth) {}

    /** @return list<array{id:string, name:string}> */
    public function listSites(Integration $i): array
    {
        $res = Http::withToken($this->auth->token($i))->timeout(30)->get(self::API.'/sites');
        if ($res->failed()) {
            throw new RuntimeException('Search Console: '.$res->json('error.message', $res->body()));
        }

        return collect($res->json('siteEntry', []))
            ->filter(fn ($s) => ($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser')
            ->map(fn ($s) => ['id' => $s['siteUrl'], 'name' => str_starts_with($s['siteUrl'], 'sc-domain:') ? 'Domena: '.substr($s['siteUrl'], 10) : $s['siteUrl']])
            ->sortBy('name')->values()->all();
    }

    public function sync(Client $client): void
    {
        $i = $client->integration('google');
        $site = $i?->setting('gsc_site');
        if (! $site) {
            return;
        }
        // Dane w Search Console mają ~2–3 dni opóźnienia
        $end = CarbonImmutable::today()->subDays(2);

        $daily = $this->query($i, $site, $end->subDays(89), $end, ['date'], 100);
        foreach ($daily as $r) {
            DB::table('search_console_daily')->updateOrInsert(
                ['client_id' => $client->id, 'date' => $r['keys'][0]],
                ['clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round($r['position'], 2)],
            );
        }

        $rows = [];
        foreach (['query' => 'query', 'page' => 'page'] as $type => $dim) {
            $now = $this->query($i, $site, $end->subDays(27), $end, [$dim], 50);
            $prev = collect($this->query($i, $site, $end->subDays(55), $end->subDays(28), [$dim], 500))->keyBy(fn ($r) => $r['keys'][0]);
            foreach ($now as $r) {
                $key = $r['keys'][0];
                $rows[] = [
                    'client_id' => $client->id, 'type' => $type, 'key' => mb_substr($key, 0, 500),
                    'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round($r['position'], 2),
                    'prev_clicks' => isset($prev[$key]) ? (int) $prev[$key]['clicks'] : null,
                    'prev_position' => isset($prev[$key]) ? round($prev[$key]['position'], 2) : null,
                ];
            }
        }
        DB::transaction(function () use ($client, $rows) {
            DB::table('search_console_rows')->where('client_id', $client->id)->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('search_console_rows')->insert($chunk);
            }
        });
    }

    private function query(Integration $i, string $site, CarbonImmutable $from, CarbonImmutable $to, array $dimensions, int $limit): array
    {
        $res = Http::withToken($this->auth->token($i))->timeout(60)
            ->post(self::API.'/sites/'.rawurlencode($site).'/searchAnalytics/query', [
                'startDate' => $from->toDateString(),
                'endDate' => $to->toDateString(),
                'dimensions' => $dimensions,
                'rowLimit' => $limit,
            ]);
        if ($res->failed()) {
            throw new RuntimeException('Search Console: '.$res->json('error.message', $res->body()));
        }

        return $res->json('rows', []);
    }

    /** Podsumowanie do widoku i raportów: 28 dni vs poprzednie 28 dni. */
    public static function summary(Client $client): ?array
    {
        $last = DB::table('search_console_daily')->where('client_id', $client->id)->max('date');
        if (! $last) {
            return null;
        }
        $end = CarbonImmutable::parse($last);
        $sum = function (CarbonImmutable $from, CarbonImmutable $to) use ($client) {
            $r = DB::table('search_console_daily')->where('client_id', $client->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) / NULLIF(SUM(impressions), 0) as position')->first();

            return ['clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions, 'position' => $r->position !== null ? round((float) $r->position, 1) : null];
        };

        return [
            'until' => $end->toDateString(),
            'now' => $sum($end->subDays(27), $end),
            'prev' => $sum($end->subDays(55), $end->subDays(28)),
        ];
    }
}
