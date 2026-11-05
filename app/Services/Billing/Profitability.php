<?php

namespace App\Services\Billing;

use App\Models\BillingItem;
use App\Models\CareTask;
use App\Models\Client;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Rentowność klientów: przychód w okresie a poświęcony czas → realna stawka za godzinę.
 * Przychód = abonamenty (wartość miesięczna × liczba miesięcy) + prace dodatkowe z kwotą
 * + u klientów agencji z ustaloną stawką: czas prac bez kwoty × stawka agencji.
 * Koszty domen/hostingów/licencji nie są przychodem z pracy – pomijamy je.
 */
class Profitability
{
    public function build(int $months = 3): array
    {
        $to = CarbonImmutable::today()->endOfDay();
        $from = CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $clients = Client::active()->where('is_demo', false)->with('agency:id,name,hourly_rate')->orderBy('name')->get();
        $tasks = CareTask::where('status', 'done')->whereBetween('done_at', [$from, $to])
            ->whereIn('client_id', $clients->pluck('id'))->get(['client_id', 'minutes', 'amount'])->groupBy('client_id');
        $subs = BillingItem::where('active', true)->where('type', 'subscription')->whereIn('client_id', $clients->pluck('id'))->get()->groupBy('client_id');
        $target = (float) Setting::get('target_hourly_rate', 0) ?: null;

        $rows = $clients->map(function (Client $c) use ($tasks, $subs, $months, $target) {
            $t = $tasks->get($c->id, collect());
            $minutes = (int) $t->sum('minutes');
            $subscriptions = round($subs->get($c->id, collect())->sum(fn (BillingItem $b) => $b->monthlyValue()) * $months, 2);
            $extra = round($t->sum(fn ($x) => $x->amount > 0 ? $x->amount : 0), 2);
            $rate = $c->agency?->hourly_rate;
            $hourly = $rate ? round($t->filter(fn ($x) => ! ($x->amount > 0))->sum('minutes') / 60 * $rate, 2) : 0.0;
            $revenue = round($subscriptions + $extra + $hourly, 2);
            $effective = $minutes > 0 ? round($revenue / ($minutes / 60), 2) : null;

            return [
                'id' => $c->id,
                'name' => $c->name,
                'agency' => $c->agency?->name,
                'revenue' => $revenue,
                'subscriptions' => $subscriptions,
                'extra' => $extra,
                'hourly' => $hourly,
                'minutes' => $minutes,
                'tasks' => $t->count(),
                'effective_rate' => $effective,
                'flag' => $minutes === 0 ? ($revenue > 0 ? 'no_time' : 'idle') : ($target && $effective < $target ? 'low' : 'ok'),
            ];
        })->filter(fn ($r) => $r['revenue'] > 0 || $r['minutes'] > 0)->values();

        $totalMinutes = $rows->sum('minutes');

        return [
            'months' => $months,
            'from' => $from->toDateString(),
            'target' => $target,
            'rows' => $rows->sortBy(fn ($r) => $r['effective_rate'] ?? PHP_INT_MAX)->values(),
            'totals' => [
                'revenue' => round($rows->sum('revenue'), 2),
                'minutes' => $totalMinutes,
                'effective_rate' => $totalMinutes > 0 ? round($rows->sum('revenue') / ($totalMinutes / 60), 2) : null,
            ],
        ];
    }
}
