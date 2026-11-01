<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Recommendation;
use App\Services\Analysis\Metrics;
use Carbon\CarbonImmutable;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(29);

        $showArchived = request()->boolean('archived');
        $clients = Client::query()->when($showArchived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->active())->withCount(['recommendations as open_count' => fn ($q) => $q->open(),
            'recommendations as critical_count' => fn ($q) => $q->open()->where('severity', 'critical')])
            ->with('latestAudit', 'integrations:id,client_id,provider,status,last_synced_at,last_error')
            ->orderBy('name')->get()
            ->map(function (Client $c) use ($from, $to) {
                $k = (new Metrics($c))->kpis($from, $to);

                return [
                    'id' => $c->id, 'name' => $c->name, 'website_url' => $c->website_url, 'currency' => $c->currency, 'is_demo' => $c->is_demo,
                    'target_roas' => $c->target_roas, 'target_cpa' => $c->target_cpa, 'goal' => $c->goal ?? 'sales', 'archived_at' => $c->archived_at,
                    'cpa' => $k['ads']['cpa'], 'sessions' => $k['analytics']['sessions'], 'leads' => $k['analytics']['leads'],
                    'cost' => $k['ads']['cost'], 'roas' => $k['ads']['roas'], 'revenue' => $k['ads']['conversion_value'], 'conversions' => $k['ads']['conversions'],
                    'open_count' => $c->open_count, 'critical_count' => $c->critical_count,
                    'audit_score' => $c->latestAudit?->score,
                    'sync_error' => $c->integrations->whereNotNull('last_error')->isNotEmpty(),
                    'last_synced_at' => $c->integrations->max('last_synced_at'),
                ];
            });

        $tasks = Recommendation::open()->whereHas('client', fn ($q) => $q->active())->with('client:id,name')->bySeverity()->limit(12)->get();

        return Inertia::render('dashboard', [
            'clients' => $clients,
            'tasks' => $tasks,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'showArchived' => $showArchived,
            'archivedCount' => Client::whereNotNull('archived_at')->count(),
        ]);
    }

    public function tasks()
    {
        $status = request('status', 'open');

        return Inertia::render('tasks', [
            'tasks' => Recommendation::with('client:id,name,currency')
                ->where('status', $status)
                ->when(request('severity'), fn ($q, $s) => $q->where('severity', $s))
                ->when(request('client'), fn ($q, $c) => $q->where('client_id', $c))
                ->bySeverity()->paginate(50)->withQueryString(),
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'filters' => request()->only(['status', 'severity', 'client']) + ['status' => $status],
        ]);
    }
}
