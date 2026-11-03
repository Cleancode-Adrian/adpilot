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

    private const SUMS = 'SUM(m.impressions) as impressions, SUM(m.clicks) as clicks, SUM(m.link_clicks) as link_clicks, SUM(m.landing_page_views) as landing_page_views, SUM(m.cost) as cost, SUM(m.conversions) as conversions, SUM(m.conversion_value) as conversion_value';

    /** Źródła GA4, które oznaczają ruch z Meta (UTM-y i odesłania z aplikacji Facebook/Instagram). */
    public const META_SOURCE_REGEX = '/(^|[^a-z])(facebook|fb|instagram|ig|meta|threads|messenger)([^a-z]|$)|facebook\.com|instagram\.com/i';

    private const PUBLISHERS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'audience_network' => 'Audience Network', 'messenger' => 'Messenger', 'threads' => 'Threads'];

    private const POSITIONS = [
        'feed' => 'aktualności', 'marketplace' => 'Marketplace', 'video_feeds' => 'filmy', 'right_hand_column' => 'prawa kolumna',
        'search' => 'wyniki wyszukiwania', 'instream_video' => 'filmy in-stream', 'facebook_reels' => 'Reels', 'facebook_reels_overlay' => 'nakładki w Reels',
        'facebook_stories' => 'relacje', 'story' => 'relacje', 'instagram_stories' => 'relacje', 'instagram_reels' => 'Reels',
        'instagram_explore' => 'Eksploruj', 'instagram_explore_grid_home' => 'Eksploruj (siatka)', 'profile_feed' => 'profil',
        'instagram_profile_feed' => 'profil', 'an_classic' => 'natywna, baner, pełnoekranowa', 'rewarded_video' => 'wideo z nagrodą',
        'messenger_inbox' => 'skrzynka odbiorcza', 'messenger_stories' => 'relacje', 'threads_feed' => 'aktualności', 'unknown' => 'inne',
    ];

    public static function placementName(string $platform, string $position): string
    {
        return (self::PUBLISHERS[$platform] ?? ucfirst(str_replace('_', ' ', $platform))).' – '.(self::POSITIONS[$position] ?? str_replace('_', ' ', $position));
    }

    /**
     * Umiejscowienia Meta w okresie: CTR linku, odsetek kliknięć kończących się wejściem na stronę (LPV)
     * i flaga „podejrzane” (kliknięcia bez wejść – przypadkowe / „za nagrodę”).
     */
    public function placements(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('placement_metrics as pm')
            ->join('campaigns as c', 'c.id', '=', 'pm.campaign_id')
            ->where('pm.client_id', $this->client->id)
            ->whereBetween('pm.date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('c.destination')->orWhereNotIn('c.destination', \App\Models\Campaign::OFF_SITE_DESTINATIONS))
            ->groupBy('pm.publisher_platform', 'pm.platform_position')
            ->selectRaw('pm.publisher_platform, pm.platform_position, SUM(pm.impressions) as impressions, SUM(pm.clicks) as clicks, SUM(pm.link_clicks) as link_clicks, SUM(pm.landing_page_views) as landing_page_views, SUM(pm.cost) as cost, SUM(pm.conversions) as conversions')
            ->get()
            ->map(function ($r) {
                $link = (int) $r->link_clicks;
                $lpv = (int) $r->landing_page_views;
                $impr = (int) $r->impressions;

                return [
                    'platform' => $r->publisher_platform,
                    'position' => $r->platform_position,
                    'name' => self::placementName($r->publisher_platform, $r->platform_position),
                    'impressions' => $impr,
                    'link_clicks' => $link,
                    'landing_page_views' => $lpv,
                    'cost' => round((float) $r->cost, 2),
                    'conversions' => round((float) $r->conversions, 2),
                    'link_ctr' => $impr ? round($link / $impr * 100, 2) : 0,
                    'lpv_rate' => $link ? round($lpv / $link * 100) : null,
                    'cost_per_lpv' => $lpv ? round((float) $r->cost / $lpv, 2) : null,
                    // ≥5 kliknięć i prawie nikt nie doszedł na stronę albo nienaturalnie wysoki CTR bez wejść
                    'suspicious' => ($link >= 5 && $lpv / max(1, $link) < 0.2) || ($impr >= 20 && $link / max(1, $impr) > 0.15 && $lpv === 0),
                ];
            })
            ->sortByDesc('link_clicks')->values();
    }

    /**
     * Co robią osoby z reklam (GA4): strony wejścia, reklamy (utm_content), zaangażowanie, zdarzenia.
     * @return array<string, array>|null  meta / google_ads → dane; null = brak danych
     */
    public function behavior(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $rows = DB::table('analytics_behavior')->where('client_id', $this->client->id)->whereBetween('date', $range)
            ->groupBy('source_group', 'kind', 'item')
            ->selectRaw('source_group, kind, item, SUM(sessions) as sessions, SUM(engaged_sessions) as engaged, SUM(engagement_seconds) as seconds, SUM(views) as views, SUM(key_events) as key_events')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $events = DB::table('analytics_source_events')->where('client_id', $this->client->id)->whereBetween('date', $range)
            ->groupBy('source_group', 'event_name')->selectRaw('source_group, event_name, SUM(count) as count')->get();

        $fmt = fn ($r) => [
            'item' => $r->item,
            'sessions' => (int) $r->sessions,
            'engaged_rate' => $r->sessions ? (int) round($r->engaged / $r->sessions * 100) : 0,
            'avg_seconds' => $r->sessions ? (int) round($r->seconds / $r->sessions) : 0,
            'views_per_session' => $r->sessions ? round($r->views / $r->sessions, 1) : 0,
            'key_events' => round((float) $r->key_events, 1),
        ];

        $out = [];
        foreach ($rows->groupBy('source_group') as $group => $g) {
            $landing = $g->where('kind', 'landing');
            $sessions = (int) $landing->sum('sessions');
            $ev = $events->where('source_group', $group)->pluck('count', 'event_name')->map(fn ($v) => (int) $v);
            $out[$group] = [
                'totals' => [
                    'sessions' => $sessions,
                    'engaged_rate' => $sessions ? (int) round($landing->sum('engaged') / $sessions * 100) : 0,
                    'avg_seconds' => $sessions ? (int) round($landing->sum('seconds') / $sessions) : 0,
                    'views_per_session' => $sessions ? round($landing->sum('views') / $sessions, 1) : 0,
                    'key_events' => round((float) $landing->sum('key_events'), 1),
                ],
                'landing' => $landing->sortByDesc('sessions')->take(15)->map($fmt)->values()->all(),
                'ads' => $g->where('kind', 'ad')->sortByDesc('sessions')->take(15)->map($fmt)->values()->all(),
                'events' => $ev->all(),
            ];
        }

        return $out;
    }

    /**
     * Lejek Meta: wyświetlenia → kliknięcia linku → wejścia na stronę (LPV, Meta) → sesje z Meta w GA4 → wyniki.
     * Każdy krok z odsetkiem przejścia i normą (poniżej normy = problem do sprawdzenia).
     */
    public function funnel(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $meta = DB::table('campaigns as c')->join('campaign_metrics as m', 'm.campaign_id', '=', 'c.id')
            ->where('c.client_id', $this->client->id)->where('c.platform', 'meta_ads')->whereBetween('m.date', $range)
            ->where(fn ($q) => $q->whereNull('c.destination')->orWhereNotIn('c.destination', \App\Models\Campaign::OFF_SITE_DESTINATIONS))
            ->selectRaw('SUM(m.impressions) as impressions, SUM(m.link_clicks) as link_clicks, SUM(m.landing_page_views) as lpv, SUM(m.cost) as cost, SUM(m.conversions) as conversions, COUNT(m.link_clicks) as with_links')
            ->first();
        if (! $meta || ! (int) $meta->impressions || ! (int) $meta->with_links) {
            return null; // brak danych Meta albo dane sprzed dodania kliknięć w link
        }

        $sources = DB::table('analytics_sources')->where('client_id', $this->client->id)->whereBetween('date', $range)
            ->groupBy('source_medium')->selectRaw('source_medium, SUM(sessions) as sessions, SUM(key_events) as key_events')->get();
        $metaSources = $sources->filter(fn ($s) => preg_match(self::META_SOURCE_REGEX, $s->source_medium));
        $hasGa = $sources->isNotEmpty();

        $impr = (int) $meta->impressions;
        $link = (int) $meta->link_clicks;
        $lpv = (int) $meta->lpv;
        $sessions = (int) $metaSources->sum('sessions');
        $results = round((float) $meta->conversions, 2);
        $rate = fn ($a, $b) => $b > 0 ? round($a / $b * 100, 1) : null;

        return [
            'cost' => round((float) $meta->cost, 2),
            'steps' => array_values(array_filter([
                ['key' => 'impressions', 'label' => 'Wyświetlenia', 'value' => $impr, 'rate' => null, 'norm' => null, 'hint' => null],
                ['key' => 'link_clicks', 'label' => 'Kliknięcia w link', 'value' => $link, 'rate' => $rate($link, $impr), 'norm' => 0.8,
                    'hint' => 'CTR linku. Poniżej 0,8% – reklama słabo przyciąga (kreacja, nagłówek, grupa odbiorców).'],
                ['key' => 'lpv', 'label' => 'Wejścia na stronę (Meta)', 'value' => $lpv, 'rate' => $rate($lpv, $link), 'norm' => 60,
                    'hint' => 'Norma 60–80%. Mniej = przypadkowe kliknięcia (Audience Network), wolna strona albo przekierowania.'],
                $hasGa ? ['key' => 'ga_sessions', 'label' => 'Sesje z Meta (GA4)', 'value' => $sessions, 'rate' => $rate($sessions, $lpv), 'norm' => 50,
                    'hint' => 'Część ruchu znika przez odmowę zgody na cookies – ale poniżej 50% sprawdź UTM-y w linku reklamy i tag GA4.'] : null,
                ['key' => 'results', 'label' => ($this->client->goal ?? 'sales') === 'sales' ? 'Zakupy (Meta)' : 'Leady (Meta)', 'value' => $results,
                    'rate' => $rate($results, $lpv), 'norm' => null, 'hint' => 'Odsetek wejść zakończonych wynikiem (konwersja strony).'],
            ])),
            'ga_sources' => $metaSources->sortByDesc('sessions')->take(6)->map(fn ($s) => ['source_medium' => $s->source_medium, 'sessions' => (int) $s->sessions])->values()->all(),
            'has_ga' => $hasGa,
        ];
    }


    /** Statystyki kampanii w okresie. */
    public function campaigns(CarbonInterface $from, CarbonInterface $to, ?string $platform = null): Collection
    {
        return DB::table('campaigns as c')
            ->join('campaign_metrics as m', 'm.campaign_id', '=', 'c.id')
            ->where('c.client_id', $this->client->id)
            ->whereBetween('m.date', [$from->toDateString(), $to->toDateString()])
            ->when($platform, fn ($q) => $q->where('c.platform', $platform))
            ->groupBy('c.id', 'c.name', 'c.platform', 'c.status', 'c.channel_type', 'c.daily_budget', 'c.destination')
            ->selectRaw('c.id, c.name, c.platform, c.status, c.channel_type, c.daily_budget, c.destination, '.self::SUMS.
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
