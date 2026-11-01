<?php

namespace App\Services\Analysis;

use App\Models\Client;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Agregacje metryk dla danego klienta i okresu. */
class Metrics
{
    public function __construct(private Client $client) {}

    public static function derive(array $r): array
    {
        $cost = (float) ($r['cost'] ?? 0);
        $clicks = (int) ($r['clicks'] ?? 0);
        $impr = (int) ($r['impressions'] ?? 0);
        $conv = (float) ($r['conversions'] ?? 0);
        $value = (float) ($r['conversion_value'] ?? 0);

        return array_merge($r, [
            'cost' => round($cost, 2),
            'conversions' => round($conv, 2),
            'conversion_value' => round($value, 2),
            'ctr' => $impr ? round($clicks / $impr * 100, 2) : 0,
            'cpc' => $clicks ? round($cost / $clicks, 2) : 0,
            'cpa' => $conv > 0 ? round($cost / $conv, 2) : null,
            'roas' => $cost > 0 ? round($value / $cost, 2) : null,
            'cvr' => $clicks ? round($conv / $clicks * 100, 2) : 0,
        ]);
    }

    private const SUMS = 'SUM(m.impressions) as impressions, SUM(m.clicks) as clicks, SUM(m.cost) as cost, SUM(m.conversions) as conversions, SUM(m.conversion_value) as conversion_value';

    /** Statystyki kampanii w okresie. */
    public function campaigns(CarbonInterface $from, CarbonInterface $to, ?string $platform = null): Collection
    {
        return DB::table('campaigns as c')
            ->join('campaign_metrics as m', 'm.campaign_id', '=', 'c.id')
            ->where('c.client_id', $this->client->id)
            ->whereBetween('m.date', [$from->toDateString(), $to->toDateString()])
            ->when($platform, fn ($q) => $q->where('c.platform', $platform))
            ->groupBy('c.id', 'c.name', 'c.platform', 'c.status', 'c.channel_type', 'c.daily_budget')
            ->selectRaw('c.id, c.name, c.platform, c.status, c.channel_type, c.daily_budget, '.self::SUMS.
                ', AVG(m.budget_lost_is) as budget_lost_is, SUM(m.frequency * m.impressions) / NULLIF(SUM(CASE WHEN m.frequency IS NOT NULL THEN m.impressions END), 0) as frequency')
            ->get()
            ->map(fn ($r) => self::derive((array) $r))
            ->sortByDesc('cost')->values();
    }

    /** Statystyki reklam w okresie. */
    public function ads(CarbonInterface $from, CarbonInterface $to, ?string $platform = null): Collection
    {
        return DB::table('ads as a')
            ->join('ad_metrics as m', 'm.ad_id', '=', 'a.id')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->where('a.client_id', $this->client->id)
            ->whereBetween('m.date', [$from->toDateString(), $to->toDateString()])
            ->when($platform, fn ($q) => $q->where('a.platform', $platform))
            ->groupBy('a.id', 'a.name', 'a.platform', 'a.status', 'a.campaign_id', 'c.name')
            ->selectRaw('a.id, a.name, a.platform, a.status, a.campaign_id, c.name as campaign_name, '.self::SUMS.
                ', SUM(m.frequency * m.impressions) / NULLIF(SUM(CASE WHEN m.frequency IS NOT NULL THEN m.impressions END), 0) as frequency')
            ->get()
            ->map(fn ($r) => self::derive((array) $r))
            ->sortByDesc('cost')->values();
    }

    /** Suma dla platform reklamowych (lub jednej platformy). */
    public function totals(CarbonInterface $from, CarbonInterface $to, ?string $platform = null): array
    {
        $r = DB::table('campaigns as c')
            ->join('campaign_metrics as m', 'm.campaign_id', '=', 'c.id')
            ->where('c.client_id', $this->client->id)
            ->whereBetween('m.date', [$from->toDateString(), $to->toDateString()])
            ->when($platform, fn ($q) => $q->where('c.platform', $platform))
            ->selectRaw(self::SUMS)->first();

        return self::derive((array) $r);
    }

    /** Dane dzienne: wydatki i przychód z reklam + sesje/przychód z GA4. */
    public function daily(CarbonInterface $from, CarbonInterface $to): array
    {
        $ads = DB::table('campaigns as c')
            ->join('campaign_metrics as m', 'm.campaign_id', '=', 'c.id')
            ->where('c.client_id', $this->client->id)
            ->whereBetween('m.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('m.date', 'c.platform')
            ->selectRaw('m.date, c.platform, SUM(m.cost) as cost, SUM(m.conversion_value) as value, SUM(m.conversions) as conversions')
            ->get()->groupBy(fn ($r) => substr($r->date, 0, 10));

        $ga = DB::table('analytics_daily')->where('client_id', $this->client->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, SUM(sessions) as sessions, SUM(transactions) as transactions, SUM(revenue) as revenue')
            ->get()->keyBy(fn ($r) => substr($r->date, 0, 10));

        $ev = DB::table('analytics_events')->where('client_id', $this->client->id)
            ->whereIn('event_name', $this->client->leadEventNames())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')->selectRaw('date, SUM(count) as n')->pluck('n', 'date')
            ->mapWithKeys(fn ($n, $d) => [substr($d, 0, 10) => (int) $n]);

        $out = [];
        for ($d = $from->toImmutable(); $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $rows = $ads->get($key, collect());
            $g = $ga->get($key);
            $out[] = [
                'date' => $key,
                'google_cost' => round((float) $rows->where('platform', 'google_ads')->sum('cost'), 2),
                'meta_cost' => round((float) $rows->where('platform', 'meta_ads')->sum('cost'), 2),
                'ads_value' => round((float) $rows->sum('value'), 2),
                'ads_conversions' => round((float) $rows->sum('conversions'), 2),
                'sessions' => (int) ($g->sessions ?? 0),
                'transactions' => round((float) ($g->transactions ?? 0), 2),
                'revenue' => round((float) ($g->revenue ?? 0), 2),
                'leads' => $ev->get($key, 0),
            ];
        }

        return $out;
    }

    /** GA4 wg kanałów. */
    public function channels(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('analytics_daily')->where('client_id', $this->client->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('channel')
            ->selectRaw('channel, SUM(sessions) as sessions, SUM(users) as users, SUM(new_users) as new_users, SUM(engaged_sessions) as engaged_sessions, SUM(transactions) as transactions, SUM(revenue) as revenue, SUM(key_events) as key_events')
            ->orderByDesc('sessions')->get()
            ->map(function ($r) {
                $r = (array) $r;
                $r['revenue'] = round((float) $r['revenue'], 2);
                $r['transactions'] = round((float) $r['transactions'], 2);
                $r['key_events'] = round((float) $r['key_events'], 2);
                $r['cvr'] = $r['sessions'] ? round($r['transactions'] / $r['sessions'] * 100, 2) : 0;
                $r['key_cvr'] = $r['sessions'] ? round($r['key_events'] / $r['sessions'] * 100, 2) : 0;
                $r['engagement_rate'] = $r['sessions'] ? round($r['engaged_sessions'] / $r['sessions'] * 100, 1) : 0;

                return $r;
            });
    }

    /** Zdarzenia leadowe GA4 wg nazwy. */
    public function events(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('analytics_events')->where('client_id', $this->client->id)
            ->whereIn('event_name', $this->client->leadEventNames())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('event_name')->selectRaw('event_name, SUM(count) as count')
            ->orderByDesc('count')->get()->map(fn ($r) => ['event_name' => $r->event_name, 'count' => (int) $r->count]);
    }

    public function analyticsTotals(CarbonInterface $from, CarbonInterface $to): array
    {
        $c = $this->channels($from, $to);
        $sessions = (int) $c->sum('sessions');
        $tx = (float) $c->sum('transactions');
        $leads = (int) $this->events($from, $to)->sum('count');

        return [
            'sessions' => $sessions,
            'users' => (int) $c->sum('users'),
            'new_users' => (int) $c->sum('new_users'),
            'transactions' => round($tx, 2),
            'revenue' => round((float) $c->sum('revenue'), 2),
            'cvr' => $sessions ? round($tx / $sessions * 100, 2) : 0,
            'aov' => $tx > 0 ? round($c->sum('revenue') / $tx, 2) : 0,
            'leads' => $leads,
            'lead_cvr' => $sessions ? round($leads / $sessions * 100, 2) : 0,
            'key_events' => round((float) $c->sum('key_events'), 2),
            'has_data' => $sessions > 0,
        ];
    }

    /** Zmiana procentowa (null gdy brak bazy). */
    public static function delta(?float $now, ?float $prev): ?float
    {
        if ($now === null || $prev === null || abs($prev) < 0.0001) {
            return null;
        }

        return round(($now - $prev) / abs($prev) * 100, 1);
    }

    /** KPI z porównaniem do poprzedniego okresu o tej samej długości. */
    public function kpis(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $pFrom = $from->copy()->subDays($days);
        $pTo = $from->copy()->subDay();

        $now = $this->totals($from, $to);
        $prev = $this->totals($pFrom, $pTo);
        $gaNow = $this->analyticsTotals($from, $to);
        $gaPrev = $this->analyticsTotals($pFrom, $pTo);

        $pick = fn (array $a, array $b, string $k) => ['value' => $a[$k], 'previous' => $b[$k], 'delta' => self::delta($a[$k], $b[$k])];

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'prev_from' => $pFrom->toDateString(), 'prev_to' => $pTo->toDateString()],
            'ads' => collect(['cost', 'clicks', 'impressions', 'conversions', 'conversion_value', 'roas', 'cpa', 'ctr', 'cpc'])
                ->mapWithKeys(fn ($k) => [$k => $pick($now, $prev, $k)])->all(),
            'analytics' => collect(['sessions', 'users', 'transactions', 'revenue', 'cvr', 'aov', 'leads', 'lead_cvr', 'key_events'])
                ->mapWithKeys(fn ($k) => [$k => $pick($gaNow, $gaPrev, $k)])->all(),
            'has_analytics' => $gaNow['has_data'],
            'goal' => $this->client->goal ?? 'sales',
            'events' => $this->events($from, $to)->values()->all(),
            'platforms' => [
                'google_ads' => $this->totals($from, $to, 'google_ads'),
                'meta_ads' => $this->totals($from, $to, 'meta_ads'),
            ],
        ];
    }
}
