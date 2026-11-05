<?php

namespace App\Http\Controllers;

use App\Models\BillingItem;
use App\Models\CareTask;
use App\Models\Invoice;
use App\Models\Recommendation;
use App\Models\SiteMonitor;
use App\Models\WpEvent;
use App\Services\Care\CarePlanner;
use App\Services\Sales\Followups;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Pulpit „Dziś” – wszystko, co wymaga uwagi, na jednym ekranie (strona startowa). */
class TodayController extends Controller
{
    public function index(Request $request, Followups $followups)
    {
        app(CarePlanner::class)->ensureAll();
        $today = CarbonImmutable::today();
        $active = fn ($q) => $q->active();

        // Pilne: strony nie działają, SSL, krytyczne alerty WordPress, krytyczne zadania reklamowe
        $urgent = collect();
        SiteMonitor::where('enabled', true)->whereHas('client', $active)->with('client:id,name')->get()
            ->each(function (SiteMonitor $m) use ($urgent) {
                if ($m->status === 'down') {
                    $urgent->push(['kind' => 'down', 'client' => $m->client->only(['id', 'name']), 'text' => 'Strona nie działa od '.$m->down_since?->format('d.m H:i'), 'url' => "/clients/{$m->client_id}/site"]);
                } elseif (($d = $m->sslDaysLeft()) !== null && $d <= 14) {
                    $urgent->push(['kind' => 'ssl', 'client' => $m->client->only(['id', 'name']), 'text' => $d < 0 ? 'Certyfikat SSL wygasł' : "Certyfikat SSL wygasa za {$d} dni", 'url' => "/clients/{$m->client_id}/site"]);
                }
            });
        WpEvent::alerts()->where('severity', 'critical')->whereHas('client', $active)->with('client:id,name')->get()->groupBy('client_id')
            ->each(fn ($g) => $urgent->push(['kind' => 'wp', 'client' => $g->first()->client->only(['id', 'name']),
                'text' => 'WordPress: '.$g->count().' krytycznych zdarzeń do sprawdzenia', 'url' => "/clients/{$g->first()->client_id}/wordpress"]));
        Recommendation::open()->where('severity', 'critical')->whereHas('client', $active)->with('client:id,name')->get()->groupBy('client_id')
            ->each(fn ($g) => $urgent->push(['kind' => 'ads', 'client' => $g->first()->client->only(['id', 'name']),
                'text' => 'Reklamy: '.$g->count().' krytycznych zadań', 'url' => "/clients/{$g->first()->client_id}/recommendations"]));

        $tasks = fn ($q) => $q->whereHas('client', $active)->with(['client:id,name', 'doneBy:id,name'])->orderBy('due_date')->orderBy('id');

        $data = [
            'urgent' => $urgent->values(),
            // zaległe i na dziś
            'tasks' => $tasks(CareTask::due($today->toDateString()))->get(CareTask::LIST_COLUMNS),
            // kolejne 7 dni
            'upcoming' => $tasks(CareTask::todo()->whereBetween('due_date', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()]))
                ->limit(30)->get(CareTask::LIST_COLUMNS),
            'requests' => CareTask::todo()->where('source', 'request')->whereHas('client', $active)->count(),
            'followups' => $followups->due()->values(),
            'doneToday' => CareTask::where('status', 'done')->where('done_at', '>=', $today)->count(),
            'minutesToday' => (int) CareTask::where('status', 'done')->where('done_at', '>=', $today)->sum('minutes'),
        ];

        if ($request->user()->is_admin) {
            $endOfMonth = $today->endOfMonth()->toDateString();
            $data['money'] = [
                'subscriptions_due' => round((float) BillingItem::where('active', true)->where('type', 'subscription')->whereNotNull('next_date')
                    ->where('next_date', '<=', $endOfMonth)->whereHas('client', $active)->sum('amount'), 2),
                'extra_open' => round((float) CareTask::where('status', 'done')->where('amount', '>', 0)->whereNull('invoiced_at')->whereHas('client', $active)->sum('amount'), 2),
                'renewals' => BillingItem::where('active', true)->where('type', '!=', 'subscription')->whereNotNull('next_date')
                    ->where('next_date', '<=', $today->addDays(14)->toDateString())->whereHas('client', $active)->with('client:id,name')->orderBy('next_date')->get()
                    ->map(fn (BillingItem $b) => ['id' => $b->id, 'name' => $b->name, 'client' => $b->client->name, 'client_id' => $b->client_id, 'next_date' => $b->next_date, 'days_left' => $b->daysLeft()]),
                'overdue_invoices' => Invoice::where('status', '!=', 'paid')->whereNotNull('payment_to')->where('payment_to', '<', $today->toDateString())
                    ->orderBy('payment_to')->get(['id', 'number', 'buyer_name', 'total', 'currency', 'payment_to', 'view_url']),
            ];
        }

        return Inertia::render('today', $data);
    }
}
