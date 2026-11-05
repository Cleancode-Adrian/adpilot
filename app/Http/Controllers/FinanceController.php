<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Billing\Fakturownia;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\Profitability;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

/** Finanse (tylko administrator): rentowność klientów i faktury z Fakturowni. */
class FinanceController extends Controller
{
    public function profitability(Request $request, Profitability $profitability)
    {
        $months = in_array((int) $request->input('months'), [1, 3, 6, 12], true) ? (int) $request->input('months') : 3;

        return Inertia::render('finance/profitability', ['data' => $profitability->build($months)]);
    }

    public function invoices(Request $request)
    {
        $status = $request->input('status');

        return Inertia::render('finance/invoices', [
            'invoices' => Invoice::query()->when($status === 'unpaid', fn ($q) => $q->where('status', '!=', 'paid'))
                ->with(['client:id,name', 'agency:id,name'])->latest('id')->limit(300)->get(),
            'status' => $status,
            'configured' => Fakturownia::configured(),
            'unpaid' => round((float) Invoice::where('status', '!=', 'paid')->sum('total'), 2),
            'overdue' => Invoice::where('status', '!=', 'paid')->whereNotNull('payment_to')->where('payment_to', '<', now()->toDateString())->count(),
        ]);
    }

    /** Podgląd pozycji do faktury dla klienta albo agencji (JSON do okna „Wystaw fakturę”). */
    public function draft(Request $request, InvoiceService $service)
    {
        $buyer = $this->buyer($request);

        return response()->json([
            'buyer' => $service->buyer($buyer),
            'lines' => $service->lines($buyer),
            'configured' => Fakturownia::configured(),
            'missing' => array_values(array_filter([
                ! $buyer->tax_id ? 'NIP' : null,
                ! $buyer->billing_street || ! $buyer->billing_city ? 'adres' : null,
            ])),
            'edit_url' => $buyer instanceof Agency ? "/agencies/{$buyer->id}/edit" : "/clients/{$buyer->id}/edit",
        ]);
    }

    public function store(Request $request, InvoiceService $service)
    {
        $data = $request->validate(['keys' => 'required|array|min:1', 'keys.*' => 'string|max:40', 'send' => 'boolean']);
        $buyer = $this->buyer($request);
        if (! Fakturownia::configured()) {
            return back()->with('error', 'Podłącz Fakturownię w Ustawieniach agencji (adres konta i kod API).');
        }
        try {
            $invoice = $service->issue($buyer, $data['keys'], (bool) ($data['send'] ?? false), $request->user()->id);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Wystawiono fakturę'.($invoice->number ? ' '.$invoice->number : '').($invoice->sent_at ? ' i wysłano ją e-mailem.' : '.'));
    }

    public function send(Invoice $invoice, InvoiceService $service)
    {
        try {
            $service->send($invoice);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Faktura wysłana e-mailem do nabywcy.');
    }

    public function paid(Invoice $invoice)
    {
        $paid = $invoice->status !== 'paid';
        $invoice->update($paid ? ['status' => 'paid', 'paid_at' => now()] : ['status' => $invoice->sent_at ? 'sent' : 'issued', 'paid_at' => null]);

        return back()->with('success', $paid ? 'Oznaczono jako opłaconą.' : 'Cofnięto oznaczenie „opłacona”.');
    }

    public function testFakturownia(Fakturownia $api)
    {
        try {
            $api->test();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Połączenie z Fakturownią działa.');
    }

    private function buyer(Request $request): Client|Agency
    {
        $data = $request->validate(['client_id' => 'required_without:agency_id|nullable|integer', 'agency_id' => 'required_without:client_id|nullable|integer']);

        return ! empty($data['agency_id']) ? Agency::findOrFail($data['agency_id']) : Client::findOrFail($data['client_id']);
    }
}
