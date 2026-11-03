<?php

namespace App\Services\Connectors;

use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** GA4 Data API – ruch i sprzedaż sklepu wg kanałów (Default Channel Group). */
class Ga4Connector
{
    public function __construct(private GoogleAuth $auth) {}

    public function listProperties(Integration $i): array
    {
        $res = Http::withToken($this->auth->token($i))->timeout(60)
            ->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', ['pageSize' => 200]);
        if ($res->failed()) {
            throw new RuntimeException('GA4: '.$res->json('error.message', $res->body()));
        }

        $props = [];
        foreach ($res->json('accountSummaries', []) as $acc) {
            foreach ($acc['propertySummaries'] ?? [] as $p) {
                $props[] = [
                    'id' => str_replace('properties/', '', $p['property']),
                    'name' => ($acc['displayName'] ?? '').' › '.($p['displayName'] ?? ''),
                ];
            }
        }

        return $props;
    }

    public function sync(Integration $i, MetricsWriter $w, string $from, string $to): void
    {
        $propertyId = $i->setting('ga4_property_id');
        if (! $propertyId) {
            return; // GA4 opcjonalne
        }

        $offset = 0;
        do {
            $res = Http::withToken($this->auth->token($i))->timeout(120)
                ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
                    'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                    'dimensions' => [['name' => 'date'], ['name' => 'sessionDefaultChannelGroup']],
                    'metrics' => [
                        ['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'newUsers'],
                        ['name' => 'engagedSessions'], ['name' => 'transactions'], ['name' => 'purchaseRevenue'],
                        ['name' => 'keyEvents'],
                    ],
                    'limit' => 10000,
                    'offset' => $offset,
                ]);
            if ($res->failed()) {
                throw new RuntimeException('GA4: '.$res->json('error.message', $res->body()));
            }

            $rows = $res->json('rows', []);
            foreach ($rows as $row) {
                $d = $row['dimensionValues'];
                $m = array_column($row['metricValues'], 'value');
                $w->analytics(Carbon::createFromFormat('Ymd', $d[0]['value'])->toDateString(), $d[1]['value'] ?: '(other)', [
                    'sessions' => (int) $m[0],
                    'users' => (int) $m[1],
                    'new_users' => (int) $m[2],
                    'engaged_sessions' => (int) $m[3],
                    'transactions' => (float) $m[4],
                    'revenue' => (float) $m[5],
                    'key_events' => (float) ($m[6] ?? 0),
                ]);
            }
            $offset += count($rows);
        } while (count($rows) === 10000);

        $this->syncEvents($i, $w, $propertyId, $from, $to);
        $this->syncSources($i, $w, $propertyId, $from, $to);
        $this->syncBehavior($i, $w, $propertyId, $from, $to);
    }

    /** Zdarzenia pokazujące, jak daleko zaszli odwiedzający z reklam. */
    public const BEHAVIOR_EVENTS = ['scroll', 'form_start', 'form_submit', 'generate_lead', 'click', 'file_download', 'phone_click', 'click_to_call', 'contact', 'sign_up', 'video_start', 'begin_checkout', 'add_to_cart', 'purchase'];

    /**
     * Co robi ruch z reklam: strony wejścia, reklamy (utm_content), zaangażowanie i zdarzenia.
     * Tylko ruch z Meta i Google Ads – pozostałe źródła pomijamy.
     */
    private function syncBehavior(Integration $i, MetricsWriter $w, string $propertyId, string $from, string $to): void
    {
        $report = function (array $dimensions, array $metrics, ?array $filter = null) use ($i, $propertyId, $from, $to) {
            $res = Http::withToken($this->auth->token($i))->timeout(120)
                ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", array_filter([
                    'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                    'dimensions' => array_map(fn ($d) => ['name' => $d], $dimensions),
                    'metrics' => array_map(fn ($m) => ['name' => $m], $metrics),
                    'dimensionFilter' => $filter,
                    'limit' => 10000,
                ]));

            return $res->successful() ? $res->json('rows', []) : null;
        };
        $metrics = ['sessions', 'engagedSessions', 'userEngagementDuration', 'screenPageViews', 'keyEvents'];

        $rows = [];
        foreach (['landing' => 'landingPage', 'ad' => 'sessionManualAdContent'] as $kind => $dim) {
            $data = $report(['date', 'sessionSourceMedium', $dim], $metrics);
            if ($data === null) {
                return; // brak uprawnień / błąd – nie nadpisujemy wcześniejszych danych
            }
            foreach ($data as $r) {
                $group = MetricsWriter::sourceGroup($r['dimensionValues'][1]['value']);
                $item = trim((string) $r['dimensionValues'][2]['value']);
                if (! $group || $item === '' || $item === '(not set)') {
                    continue;
                }
                $date = Carbon::createFromFormat('Ymd', $r['dimensionValues'][0]['value'])->toDateString();
                $m = array_column($r['metricValues'], 'value');
                $key = "$date|$group|$kind|".mb_substr($item, 0, 300);
                $rows[$key] ??= ['date' => $date, 'source_group' => $group, 'kind' => $kind, 'item' => mb_substr($item, 0, 300),
                    'sessions' => 0, 'engaged_sessions' => 0, 'engagement_seconds' => 0, 'views' => 0, 'key_events' => 0];
                $rows[$key]['sessions'] += (int) $m[0];
                $rows[$key]['engaged_sessions'] += (int) $m[1];
                $rows[$key]['engagement_seconds'] += (int) round((float) $m[2]);
                $rows[$key]['views'] += (int) $m[3];
                $rows[$key]['key_events'] += (float) $m[4];
            }
        }

        $events = [];
        $names = array_values(array_unique(array_merge(self::BEHAVIOR_EVENTS, $i->client->leadEventNames())));
        foreach ($report(['date', 'sessionSourceMedium', 'eventName'], ['eventCount'], ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $names]]]) ?? [] as $r) {
            $group = MetricsWriter::sourceGroup($r['dimensionValues'][1]['value']);
            if (! $group) {
                continue;
            }
            $date = Carbon::createFromFormat('Ymd', $r['dimensionValues'][0]['value'])->toDateString();
            $key = "$date|$group|".$r['dimensionValues'][2]['value'];
            $events[$key] ??= ['date' => $date, 'source_group' => $group, 'event_name' => $r['dimensionValues'][2]['value'], 'count' => 0];
            $events[$key]['count'] += (int) $r['metricValues'][0]['value'];
        }

        $w->replaceBehavior($from, $to, array_values($rows), array_values($events));
    }

    /** Sesje wg źródła / medium (np. facebook / paid) – do lejka „kliknięcia w reklamie → sesje w GA4”. */
    private function syncSources(Integration $i, MetricsWriter $w, string $propertyId, string $from, string $to): void
    {
        $res = Http::withToken($this->auth->token($i))->timeout(120)
            ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => [['name' => 'date'], ['name' => 'sessionSourceMedium']],
                'metrics' => [['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => 'keyEvents']],
                'limit' => 10000,
            ]);
        if ($res->failed()) {
            return; // dodatkowy raport – nie blokuje synchronizacji
        }
        foreach ($res->json('rows', []) as $row) {
            $m = array_column($row['metricValues'], 'value');
            $w->source(Carbon::createFromFormat('Ymd', $row['dimensionValues'][0]['value'])->toDateString(), $row['dimensionValues'][1]['value'] ?: '(not set)', [
                'sessions' => (int) $m[0],
                'engaged_sessions' => (int) $m[1],
                'key_events' => (float) ($m[2] ?? 0),
            ]);
        }
    }

    /** Zdarzenia leadowe (formularze, rejestracje, telefony) dzień po dniu. */
    private function syncEvents(Integration $i, MetricsWriter $w, string $propertyId, string $from, string $to): void
    {
        $res = Http::withToken($this->auth->token($i))->timeout(120)
            ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => [['name' => 'date'], ['name' => 'eventName']],
                'metrics' => [['name' => 'eventCount']],
                'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $i->client->leadEventNames()]]],
                'limit' => 10000,
            ]);
        if ($res->failed()) {
            throw new RuntimeException('GA4 (zdarzenia): '.$res->json('error.message', $res->body()));
        }
        foreach ($res->json('rows', []) as $row) {
            $w->event(
                Carbon::createFromFormat('Ymd', $row['dimensionValues'][0]['value'])->toDateString(),
                $row['dimensionValues'][1]['value'],
                (int) $row['metricValues'][0]['value'],
            );
        }
    }
}
