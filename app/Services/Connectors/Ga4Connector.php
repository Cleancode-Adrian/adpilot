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
