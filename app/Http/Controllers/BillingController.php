<?php

namespace App\Http\Controllers;

use App\Models\BillingItem;
use App\Models\CareTask;
use App\Models\Client;
use App\Models\Quote;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Rozliczenia i odnowienia: abonamenty, domeny, hostingi, licencje. */
class BillingController extends Controller
{
    /** Wszyscy klienci: do zafakturowania, odnowienia, przychód miesięczny. */
    public function index()
    {
        $items = BillingItem::where('active', true)->whereHas('client', fn ($q) => $q->active())->with('client:id,name')
            ->orderByRaw('next_date is null')->orderBy('next_date')->get();
        $endOfMonth = CarbonImmutable::today()->endOfMonth();
        $in60 = CarbonImmutable::today()->addDays(60);

        $row = fn (BillingItem $b) => [...$b->toArray(), 'days_left' => $b->daysLeft(), 'monthly_value' => $b->monthlyValue()];
        $extraWork = $this->extraWork();

        return Inertia::render('billing', [
            // prace dodatkowe wykonane w tym miesiącu (zafakturowane i nie) – część przychodu miesiąca
            'extraThisMonth' => round((float) CareTask::where('status', 'done')->where('amount', '>', 0)
                ->whereBetween('done_at', [CarbonImmutable::today()->startOfMonth(), $endOfMonth->endOfDay()])
                ->whereHas('client', fn ($q) => $q->active())->sum('amount'), 2),
            // wszystkie niezafakturowane prace dodatkowe
            'extraOpen' => round(collect($extraWork)->sum('total'), 2),
            'toInvoice' => $items->where('type', 'subscription')->filter(fn ($b) => $b->next_date && CarbonImmutable::parse($b->next_date)->lte($endOfMonth))->map($row)->values(),
            'renewals' => $items->where('type', '!=', 'subscription')->filter(fn ($b) => $b->next_date && CarbonImmutable::parse($b->next_date)->lte($in60))->map($row)->values(),
            'all' => $items->map($row)->values(),
            'extraWork' => $extraWork,
            'mrr' => round($items->sum(fn ($b) => $b->monthlyValue()), 2),
            'mrrSubscriptions' => round($items->where('type', 'subscription')->sum(fn ($b) => $b->monthlyValue()), 2),
            'types' => BillingItem::TYPES,
            'periods' => BillingItem::PERIOD_LABELS,
        ]);
    }

    public function client(Client $client)
    {
        return Inertia::render('clients/billing', [
            'client' => app(ClientController::class)->clientProps($client),
            'items' => $client->billingItems()->orderByDesc('active')->orderByRaw('next_date is null')->orderBy('next_date')->get()
                ->map(fn (BillingItem $b) => [...$b->toArray(), 'days_left' => $b->daysLeft(), 'monthly_value' => $b->monthlyValue()]),
            'extraWork' => $this->extraWork($client->id),
            'quotes' => $client->quotes()->with('items')->latest('id')->get()
                ->map(fn (Quote $q) => [...$q->only(['id', 'number', 'title', 'status', 'currency']), 'totals' => $q->totals(), 'expired' => $q->isExpired()]),
            'types' => BillingItem::TYPES,
            'periods' => BillingItem::PERIOD_LABELS,
        ]);
    }

    /** Wykonane zadania z kwotą, jeszcze niezafakturowane – pogrupowane wg klienta. */
    private function extraWork(?int $clientId = null): array
    {
        return CareTask::where('status', 'done')->whereNotNull('amount')->where('amount', '>', 0)->whereNull('invoiced_at')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->whereHas('client', fn ($q) => $q->active())->with('client:id,name,currency')->orderBy('done_at')->get()
            ->groupBy('client_id')->map(fn ($tasks) => [
                'client' => $tasks->first()->client->only(['id', 'name', 'currency']),
                'total' => round($tasks->sum('amount'), 2),
                'tasks' => $tasks->map(fn ($t) => $t->only(['id', 'title', 'amount', 'done_at', 'minutes']))->values(),
            ])->values()->all();
    }

    /** „Zafakturowano” – wszystkie niezafakturowane prace dodatkowe klienta. */
    public function invoiceExtraWork(Client $client)
    {
        $n = $client->careTasks()->where('status', 'done')->whereNotNull('amount')->where('amount', '>', 0)->whereNull('invoiced_at')->update(['invoiced_at' => now()]);

        return back()->with('success', "Oznaczono $n prac jako zafakturowane.");
    }

    public function store(Request $request, Client $client)
    {
        $client->billingItems()->create($this->data($request) + ['currency' => $client->currency ?: 'PLN']);

        return back()->with('success', 'Dodano pozycję.');
    }

    public function update(Request $request, BillingItem $item)
    {
        $item->update($this->data($request) + ['reminded_for' => null]);

        return back()->with('success', 'Zapisano.');
    }

    public function destroy(BillingItem $item)
    {
        $item->delete();

        return back()->with('success', 'Usunięto pozycję.');
    }

    /** „Rozliczono / odnowiono” – kolejna data o okres dalej. */
    public function advance(BillingItem $item)
    {
        $item->advance();

        return back()->with('success', $item->active ? 'Następny termin: '.CarbonImmutable::parse($item->next_date)->format('d.m.Y').'.' : 'Pozycja jednorazowa – zamknięta.');
    }

    /** Data wygaśnięcia domeny z rejestru. */
    public function lookup(BillingItem $item, BillingService $billing)
    {
        $date = $billing->domainExpiry($item->name);
        if (! $date) {
            return back()->with('error', 'Rejestr nie podał daty wygaśnięcia (część rejestrów ją ukrywa) – wpisz ją ręcznie z panelu rejestratora.');
        }
        $item->update(['next_date' => $date->toDateString(), 'reminded_for' => null]);

        return back()->with('success', 'Domena ważna do '.$date->format('d.m.Y').' (dane z rejestru).');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(BillingItem::TYPES))],
            'name' => 'required|string|max:255',
            'provider' => 'nullable|string|max:120',
            'amount' => 'nullable|numeric|min:0|max:9999999',
            'period' => ['required', Rule::in(array_keys(BillingItem::PERIODS))],
            'next_date' => 'nullable|date',
            'auto_renew' => 'boolean',
            'remind_days' => 'integer|min:0|max:120',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
